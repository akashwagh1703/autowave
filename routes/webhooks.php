<?php

use App\Http\Controllers\Webhooks\GatewayWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Provider webhooks on the app host (ADR-018). No session, no CSRF: every request is authenticated by a
| signature over the raw body — Meta's X-Hub-Signature-256 with the per-channel webhook key in the URL, or
| the payment gateway's signature with the webhook secret from .env.
*/

Route::get('/webhooks/meta/{webhookKey}', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('/webhooks/meta/{webhookKey}', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');

Route::post('/webhooks/billing/{gateway}', GatewayWebhookController::class)->whereAlpha('gateway')->name('webhooks.billing');
