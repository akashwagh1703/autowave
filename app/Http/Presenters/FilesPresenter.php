<?php

namespace App\Http\Presenters;

use App\Domain\Files\Models\Attachment;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class FilesPresenter
{
    public static function attachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->title ?: $attachment->original_name,
            'original_name' => $attachment->original_name,
            'kind' => $attachment->kind,
            'mime_type' => $attachment->mime_type,
            'extension' => Str::upper(pathinfo($attachment->path, PATHINFO_EXTENSION)),
            'size_bytes' => $attachment->size_bytes,
            'uploaded_by' => $attachment->uploader?->name,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'url' => route('attachments.show', $attachment),
            'download_url' => route('attachments.show', [$attachment, 'download' => 1]),
        ];
    }

    /**
     * The files card for a record, or null when the user may not see its files.
     *
     * @return array<string, mixed>|null
     */
    public static function card(Model $owner, string $ownerKey, string $uploadUrl, User $user, Tenant $tenant): ?array
    {
        $definition = config("files.owners.{$ownerKey}");

        if (! $user->can($definition['view'])) {
            return null;
        }

        $kinds = array_intersect_key(config('files.kinds'), array_flip($definition['kinds']));
        $extensions = collect($kinds)->flatMap(fn (array $kind) => array_values($kind['types']))->unique()->values();
        $largest = max(array_column($kinds, 'max_kb'));

        return [
            'items' => Attachment::query()
                ->where('attachable_type', $owner->getMorphClass())
                ->where('attachable_id', $owner->getKey())
                ->with('uploader:id,name')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Attachment $attachment) => self::attachment($attachment)),
            'upload_url' => $uploadUrl,
            'can_manage' => $user->can($definition['manage']),
            'accept' => $extensions->map(fn (string $extension) => '.'.$extension)->push($extensions->contains('jpg') ? '.jpeg' : null)->filter()->join(','),
            'hint' => __(':types, up to :max MB', ['types' => $extensions->map(fn (string $extension) => Str::upper($extension))->join(', '), 'max' => round($largest / 1024)]),
            'max_bytes' => $largest * 1024,
            'max_files' => $definition['max'],
            'title_max' => config('files.title_max'),
            'storage' => app(StorageAllowance::class)->summary($tenant),
        ];
    }
}
