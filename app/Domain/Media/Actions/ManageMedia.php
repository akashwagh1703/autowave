<?php

namespace App\Domain\Media\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

/**
 * Uploads and manages the current tenant's images (config('website.media')).
 *
 * - Only JPEG, PNG and WebP (checked by content, not just extension), within the size and
 *   dimension limits. SVG is refused: it can carry scripts.
 * - Files are stored under tenant/{tenant_id}/{collection path}/ with a random name; the original
 *   name is kept for display only.
 * - Single-image collections (logo, hero) replace the previous image.
 */
class ManageMedia
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<string> collections managed in the website editor (not product images) */
    public static function websiteCollections(): array
    {
        return array_keys(array_filter(config('website.media.collections'), fn (array $definition) => $definition['website'] ?? true));
    }

    public function upload(mixed $file, string $collection, ?User $actor = null, ?string $alt = null): Media
    {
        $collections = config('website.media.collections');
        $definition = $collections[$collection] ?? throw ValidationException::withMessages(['collection' => __('Unknown image type.')]);
        $limits = config('website.media');

        Validator::make(['file' => $file, 'alt' => $alt], [
            'file' => [
                'required',
                'file',
                'max:'.$limits['max_kb'],
                'mimes:'.implode(',', $limits['mimes']),
                'mimetypes:image/jpeg,image/png,image/webp',
                Rule::dimensions()->minWidth($limits['min_dimension'])->minHeight($limits['min_dimension'])
                    ->maxWidth($limits['max_dimension'])->maxHeight($limits['max_dimension']),
            ],
            'alt' => ['nullable', 'string', 'max:150'],
        ], [
            'file.max' => __('The image must be :max MB or smaller.', ['max' => round($limits['max_kb'] / 1024, 1)]),
            'file.mimes' => __('Upload a JPG, PNG or WebP image.'),
            'file.mimetypes' => __('Upload a JPG, PNG or WebP image.'),
            'file.dimensions' => __('The image must be between :min and :max pixels on each side.', ['min' => $limits['min_dimension'], 'max' => $limits['max_dimension']]),
        ])->validate();

        /** @var UploadedFile $file */
        $single = ($definition['max'] ?? 1) === 1;

        if (! $single && Media::query()->where('collection', $collection)->count() >= $definition['max']) {
            throw ValidationException::withMessages(['file' => __('You can upload up to :max images here. Remove one first.', ['max' => $definition['max']])]);
        }

        [$width, $height] = getimagesize($file->getRealPath()) ?: [0, 0];
        $disk = $limits['disk'];
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        try {
            $path = $file->storeAs(
                'tenant/'.$this->context->tenant()->id.'/'.$definition['path'],
                $collection.'-'.Str::lower((string) Str::ulid()).'.'.$extension,
                ['disk' => $disk],
            );
        } catch (FilesystemException $exception) {
            report($exception);
            $path = false;
        }

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('The image could not be saved. Please try again in a minute.')]);
        }

        $replaced = $single ? Media::query()->where('collection', $collection)->get() : collect();

        $media = DB::transaction(function () use ($collection, $disk, $path, $file, $width, $height, $alt, $actor, $replaced) {
            $replaced->each->delete();

            return Media::query()->create([
                'collection' => $collection,
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'alt' => filled($alt) ? trim($alt) : null,
                'sort_order' => ((int) Media::query()->where('collection', $collection)->max('sort_order')) + 10,
                'uploaded_by_user_id' => $actor?->id,
            ]);
        });

        $replaced->each(fn (Media $old) => $this->deleteFile($old));
        $this->audit->log('media.uploaded', $media, ['collection' => $collection]);

        return $media;
    }

    public function delete(Media $media): void
    {
        $media->delete();
        $this->deleteFile($media);
        $this->audit->log('media.deleted', null, ['collection' => $media->collection, 'media_id' => $media->id]);
    }

    /** The row is already gone; a file left behind by a storage outage is only wasted space. */
    private function deleteFile(Media $media): void
    {
        try {
            Storage::disk($media->disk)->delete($media->path);
        } catch (FilesystemException $exception) {
            report($exception);
        }
    }

    public function updateAlt(Media $media, ?string $alt): Media
    {
        Validator::make(['alt' => $alt], ['alt' => ['nullable', 'string', 'max:150']])->validate();
        $media->update(['alt' => filled($alt) ? trim($alt) : null]);

        return $media;
    }

    /** @param  list<int>  $ids  every image id of the collection in the new order */
    public function reorder(string $collection, array $ids): void
    {
        $existing = Media::query()->where('collection', $collection)->pluck('id')->all();
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (count($ids) !== count($existing) || array_diff($ids, $existing) !== []) {
            throw ValidationException::withMessages(['ids' => __('The image list is out of date. Reload the page and try again.')]);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $id) {
                Media::query()->whereKey($id)->update(['sort_order' => ($index + 1) * 10]);
            }
        });
    }
}
