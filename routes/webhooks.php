<?php

use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Provider webhooks on the app host (ADR-018). No session, no CSRF: every request is authenticated by
| the per-channel webhook key in the URL plus Meta's X-Hub-Signature-256 over the raw body.
*/

Route::get('/webhooks/meta/{webhookKey}', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('/webhooks/meta/{webhookKey}', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');
