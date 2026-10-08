<?php

namespace App\Domain\Service\Models;

use App\Domain\Commerce\Models\Product;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line inside a package: either another service or a product (album, print, etc.).
 */
#[Fillable(['tenant_id', 'package_service_id', 'included_service_id', 'product_id', 'sort_order'])]
class PackageItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'package_service_id');
    }

    public function includedService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'included_service_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
