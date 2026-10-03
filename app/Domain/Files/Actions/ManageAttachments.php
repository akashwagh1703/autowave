<?php

namespace App\Domain\Files\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Files\Models\Attachment;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use ZipArchive;

/**
 * Uploads and deletes the current tenant's documents and videos (config('files')).
 *
 * - The type comes from the file's content (finfo), never its name or the browser's claim. Word and Excel
 *   files are ZIP containers, so their inner layout is checked too. Anything not listed is refused.
 * - Files are stored under tenant/{tenant_id}/{owner folder}/ with a random name, on the private or the
 *   public disk as the owner type says (customer documents are private; catalog videos and brochures public).
 * - Every upload counts against the business's storage allowance.
 */
class ManageAttachments
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly StorageAllowance $allowance,
    ) {}

    public function upload(Model $owner, string $ownerKey, mixed $file, ?User $actor = null, ?string $title = null): Attachment
    {
        $definition = config("files.owners.{$ownerKey}") ?? throw new InvalidArgumentException("Unknown attachment owner [{$ownerKey}].");
        $visibility = $definition['visibility'] ?? Attachment::PRIVATE;
        $kinds = self::kindsFor($definition);
        $largest = max(array_column($kinds, 'max_kb'));

        Validator::make(['file' => $file, 'title' => $title], [
            'file' => ['required', 'file', 'max:'.$largest],
            'title' => ['nullable', 'string', 'max:'.config('files.title_max')],
        ], [
            'file.max' => __('The file must be :max MB or smaller.', ['max' => round($largest / 1024)]),
        ])->validate();

        /** @var UploadedFile $file */
        [$kind, $mime] = $this->detect($file, $kinds);

        if ($file->getSize() > $kinds[$kind]['max_kb'] * 1024) {
            throw ValidationException::withMessages(['file' => __(':kind files can be up to :max MB.', ['kind' => $kinds[$kind]['label'], 'max' => round($kinds[$kind]['max_kb'] / 1024)])]);
        }

        $existing = $owner->morphMany(Attachment::class, 'attachable');

        if ((clone $existing)->count() >= $definition['max']) {
            throw ValidationException::withMessages(['file' => __('Up to :max files can be attached here. Delete one first.', ['max' => $definition['max']])]);
        }

        $kindMax = $definition['kind_max'][$kind] ?? null;

        if ($kindMax !== null && (clone $existing)->where('kind', $kind)->count() >= $kindMax) {
            throw ValidationException::withMessages(['file' => trans_choice('{1} Only one :kind can be added here. Delete the current one first.|[2,*] Up to :max :kind files can be added here. Delete one first.', $kindMax, [
                'kind' => Str::lower($kinds[$kind]['label']),
                'max' => $kindMax,
            ])]);
        }

        $tenant = $this->context->tenant();
        $this->allowance->ensureRoomFor($tenant, (int) $file->getSize());

        $disk = (string) config("files.disks.{$visibility}");

        try {
            $path = $file->storeAs(
                "tenant/{$tenant->id}/{$definition['folder']}",
                Str::lower((string) Str::ulid()).'.'.$kinds[$kind]['types'][$mime],
                ['disk' => $disk],
            );
        } catch (FilesystemException $exception) {
            report($exception);
            $path = false;
        }

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be saved. Please try again in a minute.')]);
        }

        $attachment = Attachment::query()->create([
            'attachable_type' => $owner->getMorphClass(),
            'attachable_id' => $owner->getKey(),
            'kind' => $kind,
            'visibility' => $visibility,
            'disk' => $disk,
            'path' => $path,
            'title' => filled($title) ? trim($title) : null,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by_user_id' => $actor?->id,
        ]);

        $this->audit->log('attachment.uploaded', $attachment, ['owner' => $ownerKey, 'owner_id' => $owner->getKey(), 'kind' => $kind]);

        return $attachment;
    }

    public function delete(Attachment $attachment): void
    {
        $attachment->delete();

        try {
            Storage::disk($attachment->disk)->delete($attachment->path);
        } catch (FilesystemException $exception) {
            report($exception);
        }

        $this->audit->log('attachment.deleted', null, ['attachment_id' => $attachment->id, 'kind' => $attachment->kind, 'name' => $attachment->original_name]);
    }

    /** Removes every file of a record that is being deleted, so it stops counting against the allowance. */
    public function deleteAllFor(Model $owner): void
    {
        Attachment::query()
            ->where('attachable_type', $owner->getMorphClass())
            ->where('attachable_id', $owner->getKey())
            ->get()
            ->each(fn (Attachment $attachment) => $this->delete($attachment));
    }

    /**
     * An owner's kinds in its own order (the first kind listing a content type wins), with the owner's
     * size limits (`kind_max_kb`) applied.
     *
     * @param  array<string, mixed>  $definition  config('files.owners.*')
     * @return array<string, array{label: string, max_kb: int, types: array<string, string>}>
     */
    public static function kindsFor(array $definition): array
    {
        $kinds = [];

        foreach ($definition['kinds'] as $key) {
            $kind = config("files.kinds.{$key}");
            $kinds[$key] = [...$kind, 'max_kb' => (int) ($definition['kind_max_kb'][$key] ?? $kind['max_kb'])];
        }

        return $kinds;
    }

    /**
     * @param  array<string, array{label: string, max_kb: int, types: array<string, string>}>  $kinds
     * @return array{0: string, 1: string} kind and content type
     */
    private function detect(UploadedFile $file, array $kinds): array
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            $mime = $this->officeType($file->getRealPath()) ?? $mime;
        }

        foreach ($kinds as $kind => $definition) {
            if (isset($definition['types'][$mime])) {
                return [$kind, $mime];
            }
        }

        $allowed = collect($kinds)->flatMap(fn (array $definition) => array_values($definition['types']))->unique()->map(fn (string $extension) => Str::upper($extension))->join(', ');

        throw ValidationException::withMessages(['file' => __('This type of file is not allowed. Upload :types.', ['types' => $allowed])]);
    }

    /** Word and Excel files are ZIP archives with a known layout; older libmagic only reports "zip". */
    private function officeType(string $path): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return null;
            }

            return match (true) {
                $zip->locateName('word/document.xml') !== false => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $zip->locateName('xl/workbook.xml') !== false => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => null,
            };
        } finally {
            $zip->close();
        }
    }
}
