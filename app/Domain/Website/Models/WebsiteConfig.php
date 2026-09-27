<?php

namespace App\Domain\Website\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One per tenant: chosen template, theme overrides (colours) and SEO defaults.
 */
#[Fillable(['tenant_id', 'website_template_id', 'theme', 'seo', 'status', 'published_at'])]
class WebsiteConfig extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'seo' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WebsiteTemplate::class, 'website_template_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
