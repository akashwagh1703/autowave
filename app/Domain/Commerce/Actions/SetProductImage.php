<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Product;
use App\Domain\Media\Actions\SetRecordImage;
use App\Models\User;

/**
 * A product's single image (collection `product`, stored under tenant/{tenant_id}/products/).
 * Replacing or removing it deletes the old file.
 */
class SetProductImage
{
    public function __construct(private readonly SetRecordImage $images) {}

    public function upload(Product $product, mixed $file, ?User $actor = null): Product
    {
        $this->images->upload($product, $file, 'product', $actor);

        return $product;
    }

    public function remove(Product $product): Product
    {
        $this->images->remove($product);

        return $product;
    }
}
