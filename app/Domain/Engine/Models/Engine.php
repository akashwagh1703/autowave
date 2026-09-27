<?php

namespace App\Domain\Engine\Models;

use App\Support\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'description', 'status', 'configuration', 'sort_order'])]
class Engine extends Model
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

    /** @return list<string> */
    public function requiredModuleCodes(): array
    {
        return array_values($this->configuration['requires_modules'] ?? []);
    }
}
