<?php

namespace App\Http\Presenters;

use App\Domain\Files\Actions\ManageAttachments;
use App\Domain\Files\Models\Attachment;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
            'public' => ! $attachment->isPrivate(),
            'url' => $attachment->isPrivate() ? route('attachments.show', $attachment) : $attachment->publicUrl(),
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
        return self::cards(collect([$owner]), $ownerKey, fn () => $uploadUrl, $user, $tenant)?->get($owner->getKey());
    }

    /**
     * Files cards for several records of one type, keyed by record id (one query for all of them).
     *
     * @param  Collection<int, Model>  $owners
     * @param  Closure(Model): string  $uploadUrl
     * @return Collection<int, array<string, mixed>>|null
     */
    public static function cards(Collection $owners, string $ownerKey, Closure $uploadUrl, User $user, Tenant $tenant): ?Collection
    {
        $definition = config("files.owners.{$ownerKey}");

        if (! $user->can($definition['view'])) {
            return null;
        }

        $items = Attachment::query()
            ->where('attachable_type', $owners->first()?->getMorphClass())
            ->whereIn('attachable_id', $owners->map(fn (Model $owner) => $owner->getKey())->all())
            ->with('uploader:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('attachable_id');

        $shared = [
            ...self::rules($ownerKey),
            'can_manage' => $user->can($definition['manage']),
            'public' => ($definition['visibility'] ?? Attachment::PRIVATE) === Attachment::PUBLIC,
            'storage' => app(StorageAllowance::class)->summary($tenant),
        ];

        return $owners->mapWithKeys(fn (Model $owner) => [$owner->getKey() => [
            ...$shared,
            'items' => ($items->get($owner->getKey()) ?? collect())->map(fn (Attachment $attachment) => self::attachment($attachment))->values(),
            'upload_url' => $uploadUrl($owner),
        ]]);
    }

    /**
     * What an owner type accepts, for the file picker and client-side size checks (the server checks again).
     *
     * @return array{kinds: list<array<string, mixed>>, accept: string, hint: string, max_bytes: int, max_files: int, title_max: int}
     */
    public static function rules(string $ownerKey): array
    {
        $definition = config("files.owners.{$ownerKey}");
        $kinds = collect(ManageAttachments::kindsFor($definition))
            ->map(fn (array $kind, string $key) => [
                'kind' => $key,
                'label' => $kind['label'],
                'extensions' => collect($kind['types'])->values()->unique()->values()->all(),
                'max_bytes' => $kind['max_kb'] * 1024,
                'max_files' => $definition['kind_max'][$key] ?? $definition['max'],
            ])
            ->values();
        $extensions = $kinds->flatMap(fn (array $kind) => $kind['extensions'])->unique()->values();

        return [
            'kinds' => $kinds->all(),
            'accept' => $extensions->map(fn (string $extension) => '.'.$extension)->push($extensions->contains('jpg') ? '.jpeg' : null)->filter()->join(','),
            'hint' => $kinds->map(fn (array $kind) => __(':label: :types, up to :max MB', [
                'label' => $kind['label'],
                'types' => collect($kind['extensions'])->map(fn (string $extension) => Str::upper($extension))->join(', '),
                'max' => round($kind['max_bytes'] / 1024 / 1024),
            ]))->join(' · '),
            'max_bytes' => $kinds->max('max_bytes'),
            'max_files' => $definition['max'],
            'title_max' => config('files.title_max'),
        ];
    }
}
