<?php

namespace App\Domain\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "Book a demo" request from the marketing site (platform data, not tenant-scoped), followed up in
 * Super Admin → Demo requests. `lead_id` points at the copy in the AutoWave Internal CRM, when it exists.
 */
#[Fillable(['name', 'phone', 'email', 'business_name', 'industry', 'city', 'message', 'status', 'note', 'lead_id', 'handled_by_user_id', 'handled_at'])]
class DemoRequest extends Model
{
    public const NEW = 'new';

    public const CONTACTED = 'contacted';

    public const CONVERTED = 'converted';

    public const CLOSED = 'closed';

    public const STATUSES = [self::NEW, self::CONTACTED, self::CONVERTED, self::CLOSED];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }
}
