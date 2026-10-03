<?php

namespace App\Http\Presenters;

use App\Domain\Billing\Enums\SubscriptionState;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Models\Tenant;

/**
 * Browser-safe shapes for billing pages. Amounts are paise; timestamps ISO-8601 UTC. Never includes proof
 * file paths or gateway keys.
 */
final class BillingPresenter
{
    /** @return array<string, mixed> */
    public static function subscription(Tenant $tenant, Entitlements $entitlements): array
    {
        $subscription = $entitlements->subscription($tenant);
        $state = $entitlements->state($tenant);
        $plan = $subscription?->planAt();
        $changesLater = $subscription?->plan_changes_at?->isFuture();
        $endsAt = $subscription?->ends_at;

        return [
            'state' => $state->value,
            'label' => $state->label(),
            'access' => $entitlements->access($tenant),
            'plan' => $plan ? ['code' => $plan->code, 'name' => $plan->name] : null,
            'period' => $subscription?->periodAt(),
            'is_trial' => (bool) $subscription?->is_trial,
            'ends_at' => $endsAt?->toIso8601String(),
            'days_left' => $endsAt && $endsAt->isFuture() ? (int) ceil(now()->diffInSeconds($endsAt) / 86400) : 0,
            'read_only_at' => $endsAt?->copy()->addDays((int) config('billing.grace_days'))->toIso8601String(),
            'locks_at' => $endsAt?->copy()->addDays((int) config('billing.lock_after_days'))->toIso8601String(),
            'next_plan' => $changesLater && $subscription->nextPlan ? ['code' => $subscription->nextPlan->code, 'name' => $subscription->nextPlan->name] : null,
            'next_period' => $changesLater ? $subscription->next_period : null,
            'plan_changes_at' => $changesLater ? $subscription->plan_changes_at->toIso8601String() : null,
            'internal' => (bool) $tenant->is_internal,
            'unlimited' => $state === SubscriptionState::Unlimited,
        ];
    }

    /** @return array<string, mixed> */
    public static function plan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly' => $plan->price_monthly,
            'price_yearly' => $plan->price_yearly,
            'limits' => array_replace(array_fill_keys(Plan::LIMIT_KEYS, null), $plan->limits ?? []),
            'is_public' => $plan->is_public,
            'is_active' => $plan->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(BillingPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'plan' => $payment->relationLoaded('plan') ? $payment->plan?->name : null,
            'period' => $payment->period,
            'kind' => $payment->kind,
            'credit' => $payment->credit,
            'amount' => $payment->amount,
            'tax_amount' => $payment->tax_amount,
            'total' => $payment->total,
            'method' => $payment->method,
            'method_label' => config("billing.methods.{$payment->method}", $payment->method),
            'status' => $payment->status,
            'reference' => $payment->reference,
            'paid_on' => $payment->paid_on?->toDateString(),
            'has_proof' => $payment->proof_path !== null,
            'buyer_gstin' => $payment->buyer_gstin,
            'note' => $payment->note,
            'rejection_reason' => $payment->rejection_reason,
            'covers_from' => $payment->covers_from?->toIso8601String(),
            'covers_until' => $payment->covers_until?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'reviewed_at' => $payment->reviewed_at?->toIso8601String(),
            'invoice' => $payment->relationLoaded('invoice') && $payment->invoice ? ['id' => $payment->invoice->id, 'number' => $payment->invoice->number] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(BillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'type' => $invoice->type,
            'title' => $invoice->type === BillingInvoice::TAX_INVOICE ? __('Tax Invoice') : __('Invoice'),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'seller' => $invoice->seller,
            'buyer' => $invoice->buyer,
            'lines' => $invoice->lines,
            'subtotal' => $invoice->subtotal,
            'tax' => $invoice->tax ?? [],
            'tax_amount' => $invoice->tax_amount,
            'total' => $invoice->total,
            'payment' => $invoice->relationLoaded('payment') && $invoice->payment ? [
                'method_label' => config("billing.methods.{$invoice->payment->method}", $invoice->payment->method),
                'reference' => $invoice->payment->reference,
                'paid_on' => $invoice->payment->paid_on?->toDateString(),
            ] : null,
        ];
    }
}
