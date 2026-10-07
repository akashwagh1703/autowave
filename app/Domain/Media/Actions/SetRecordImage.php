<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The single photo of a product, service or course (`image_media_id`, `image` relation), stored through
 * ManageMedia in the given collection (same file checks as website images). Replacing or removing it
 * deletes the old file.
 */
class SetRecordImage
{
    public function __construct(private readonly ManageMedia $media) {}

    public function upload(Model $record, mixed $file, string $collection, ?User $actor = null): Model
    {
        $previous = $record->image_media_id ? Media::query()->find($record->image_media_id) : null;

        try {
            $image = $this->media->upload($file, $collection, $actor, $record->name);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['image' => collect($exception->errors())->flatten()->first()]);
        }

        $record->forceFill(['image_media_id' => $image->id])->save();

        if ($previous) {
            $this->media->delete($previous);
        }

        return $record->setRelation('image', $image);
    }

    public function remove(Model $record): Model
    {
        $image = $record->image_media_id ? Media::query()->find($record->image_media_id) : null;
        $record->forceFill(['image_media_id' => null])->save();

        if ($image) {
            $this->media->delete($image);
        }

        return $record->setRelation('image', null);
    }
}
