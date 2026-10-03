<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Product;
use App\Domain\Files\Actions\ManageAttachments;

/**
 * Soft delete: orders keep their copy of the name and price, and the stock history stays. The
 * product disappears from the product list, the order form and the website. Its video and
 * brochures are deleted.
 */
class DeleteProduct
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ManageAttachments $attachments,
    ) {}

    public function handle(Product $product): void
    {
        $product->delete();
        $this->audit->log('product.deleted', $product, ['name' => $product->name]);
        $this->attachments->deleteAllFor($product);
    }
}
