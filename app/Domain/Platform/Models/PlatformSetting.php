<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A platform-wide setting changed by platform admins in Super Admin → Settings. Not tenant-scoped. */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
