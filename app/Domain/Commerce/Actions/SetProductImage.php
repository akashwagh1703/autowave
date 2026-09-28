<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Product;
use App\Domain\Media\Actions\ManageMedia;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A product's single image, stored through ManageMedia (collection `product`, same file checks as
 * website images) under tenant/{tenant_id}/products/. Replacing or removing it deletes the old file.
 */
class SetProductImage
{
    public function __construct(private readonly ManageMedia $media) {}

    public function upload(Product $product, mixed $file, ?User $actor = null): Product
    {
        $previous = $product->image_media_id ? Media::query()->find($product->image_media_id) : null;

        try {
            $image = $this->media->upload($file, 'product', $actor, $product->name);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['image' => collect($exception->errors())->flatten()->first()]);
        }

        $product->forceFill(['image_media_id' => $image->id])->save();

        if ($previous) {
            $this->media->delete($previous);
        }

        return $product->setRelation('image', $image);
    }

    public function remove(Product $product): Product
    {
        $image = $product->image_media_id ? Media::query()->find($product->image_media_id) : null;
        $product->forceFill(['image_media_id' => null])->save();

        if ($image) {
            $this->media->delete($image);
        }

        return $product->setRelation('image', null);
    }
}
