<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan businesses subscribe to (platform data, not tenant-scoped). Prices are in paise; `limits` holds
 * members, storage_mb, ai_tokens, automations (null = unlimited) and instagram. See config('billing.plans').
 */
#[Fillable(['code', 'name', 'description', 'price_monthly', 'price_yearly', 'limits', 'is_public', 'is_active', 'sort_order'])]
class Plan extends Model
{
    public const LIMIT_KEYS = ['members', 'storage_mb', 'ai_tokens', 'automations', 'instagram'];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'limits' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Plans an owner can choose. */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true)->orderBy('sort_order');
    }

    public function price(string $period): int
    {
        return $period === 'yearly' ? $this->price_yearly : $this->price_monthly;
    }

    public function limit(string $key): mixed
    {
        return ($this->limits ?? [])[$key] ?? null;
    }

    public function isTrial(): bool
    {
        return $this->code === config('billing.trial.plan');
    }
}
