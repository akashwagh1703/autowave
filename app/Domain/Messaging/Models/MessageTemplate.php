<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A WhatsApp message template synced from Meta. Only APPROVED templates can be sent. */
#[Fillable([
    'tenant_id', 'channel', 'name', 'language', 'category', 'status', 'body', 'variables',
    'provider_template_id', 'synced_at',
])]
class MessageTemplate extends Model
{
    use BelongsToTenant;

    public const APPROVED = 'APPROVED';

    protected function casts(): array
    {
        return [
            'variables' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /** The body with {{1}}, {{2}}… replaced by the given parameters. */
    public function render(array $params): string
    {
        return (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn (array $match) => (string) ($params[(int) $match[1] - 1] ?? $match[0]), (string) $this->body);
    }
}
