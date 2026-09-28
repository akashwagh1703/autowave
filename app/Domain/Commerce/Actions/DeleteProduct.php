<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Product;

/**
 * Soft delete: orders keep their copy of the name and price, and the stock history stays. The
 * product disappears from the product list, the order form and the website.
 */
class DeleteProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Product $product): void
    {
        $product->delete();
        $this->audit->log('product.deleted', $product, ['name' => $product->name]);
    }
}
