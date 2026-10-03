<?php

namespace App\Domain\Files\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A document or video attached to a record (config('files')). `private` files sit on the private disk and
 * are only streamed by AttachmentController after a permission check; `public` files load from the media URL.
 */
#[Fillable(['tenant_id', 'attachable_type', 'attachable_id', 'kind', 'visibility', 'disk', 'path', 'title', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by_user_id'])]
class Attachment extends Model
{
    use BelongsToTenant;

    public const PRIVATE = 'private';

    public const PUBLIC = 'public';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::PRIVATE;
    }

    /** Where a browser loads a public file; host-relative on the local public disk, like Media::url(). */
    public function publicUrl(): string
    {
        return $this->disk === 'public'
            ? '/storage/'.ltrim($this->path, '/')
            : Storage::disk($this->disk)->url($this->path);
    }

    /** A name for the download that keeps the stored extension, whatever the original name was. */
    public function downloadName(): string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $base = pathinfo($this->title ?: $this->original_name, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\pL\pN _.-]+/u', '', $base)) ?: 'file';

        return mb_substr($base, 0, 100).'.'.$extension;
    }
}
