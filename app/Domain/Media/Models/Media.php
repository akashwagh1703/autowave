<?php

namespace App\Domain\Media\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded image owned by a tenant (logo, hero, gallery). The file lives on `disk` at `path`
 * (tenant/{tenant_id}/...); config('website.media.collections') defines the collections.
 */
#[Fillable(['tenant_id', 'collection', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'width', 'height', 'alt', 'sort_order', 'uploaded_by_user_id'])]
class Media extends Model
{
    use BelongsToTenant;

    protected $table = 'media';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * On the local public disk the URL is host-relative, so the image loads on the tenant's
     * subdomain and on custom domains alike.
     */
    public function url(): string
    {
        return $this->disk === 'public'
            ? '/storage/'.ltrim($this->path, '/')
            : Storage::disk($this->disk)->url($this->path);
    }

    public function scopeInCollection(Builder $query, string $collection): void
    {
        $query->where('collection', $collection)->orderBy('sort_order')->orderBy('id');
    }
}
