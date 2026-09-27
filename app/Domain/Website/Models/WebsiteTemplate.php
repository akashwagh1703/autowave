<?php

namespace App\Domain\Website\Models;

use App\Support\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform catalogue entry (synced from config/catalog.php). Controls look and feel only.
 */
#[Fillable(['code', 'name', 'description', 'status', 'configuration', 'sort_order'])]
class WebsiteTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'configuration' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === CatalogStatus::Active;
    }

    /** @return array<string, string> */
    public function theme(): array
    {
        return $this->configuration['theme'] ?? [];
    }
}
