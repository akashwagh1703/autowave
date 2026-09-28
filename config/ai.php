<?php

use App\Domain\AI\Providers\FakeProvider;
use App\Domain\AI\Providers\OpenRouterProvider;

/*
|--------------------------------------------------------------------------
| AI (Phase 9 — ADR-008, ADR-019)
|--------------------------------------------------------------------------
|
| Business code calls AIService, never a provider. AI drafts, summarises and
| extracts; the application decides. Everything keeps working when AI is off,
| not configured, over its monthly cap or failing.
|
| `fake` answers deterministically without a network call (tests, local
| development without a key); its output is labelled as sample text.
|
*/

return [

    'provider' => env('AI_PROVIDER', 'openrouter'),

    'providers' => [
        'openrouter' => ['class' => OpenRouterProvider::class, 'label' => 'OpenRouter'],
        'fake' => ['class' => FakeProvider::class, 'label' => 'Sample answers (no AI provider)'],
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
        'timeout' => 30,
        // Sent as HTTP-Referer / X-Title so OpenRouter can attribute usage to the app.
        'referer' => env('APP_URL'),
        'title' => env('APP_NAME', 'AutoWave'),
    ],

    /*
    | Per-feature settings. `model` overrides the provider default.
    */
    'features' => [
        'reply' => ['label' => 'Reply drafts', 'max_tokens' => 350, 'temperature' => 0.5],
        'summary' => ['label' => 'Summaries', 'max_tokens' => 400, 'temperature' => 0.2],
        'extraction' => ['label' => 'Lead details', 'max_tokens' => 400, 'temperature' => 0.0],
        'assistant' => ['label' => 'Assistant', 'max_tokens' => 700, 'temperature' => 0.2],
        'copy' => ['label' => 'Writing help', 'max_tokens' => 700, 'temperature' => 0.7],
    ],

    'limits' => [
        // Tokens per business per calendar month (UTC). A platform admin can override it per business.
        'monthly_tokens' => (int) env('AI_MONTHLY_TOKENS', 300000),
        // Requests per user per minute to the AI endpoints.
        'per_minute' => 20,
    ],

    'context' => [
        'messages' => 20,
        'activities' => 40,
        'message_chars' => 800,
        'services' => 40,
        'products' => 40,
        'assistant_turns' => 10,
        'assistant_chars' => 1000,
        'assistant_tool_rounds' => 3,
        'assistant_rows' => 25,
    ],

    'extraction' => [
        // Wait for the contact to finish typing before one extraction runs.
        'delay_seconds' => 120,
        'max_auto_runs' => 5,
        // Inbound text shorter than this (all messages together) is not worth an AI call.
        'min_chars' => 15,
    ],

    // Tenant setting `ai` overrides these (Settings → AI).
    'defaults' => [
        'enabled' => true,
        'auto_extract' => (bool) env('AI_AUTO_EXTRACT', true),
        'tone' => 'friendly',
        'notes' => null,
    ],

    'tones' => [
        'friendly' => 'Friendly and warm',
        'professional' => 'Professional',
        'short' => 'Short and to the point',
    ],

    'notes_max' => 1500,

    /*
    | Writing help. `permission`: needed on top of ai.use. `context`: what the
    | text is for, shown to the model.
    */
    'copy_kinds' => [
        'website_field' => ['label' => 'Website text', 'permission' => 'website.manage', 'max' => 2000],
        'automation_message' => ['label' => 'Automation message', 'permission' => ['automation.create', 'automation.update'], 'max' => 1000],
        'whatsapp_promotion' => ['label' => 'WhatsApp promotion', 'max' => 1000, 'marketing' => true, 'description' => 'A short WhatsApp message promoting an offer or service to existing customers.'],
        'offer' => ['label' => 'Offer announcement', 'max' => 600, 'marketing' => true, 'description' => 'A short announcement of a discount or special offer.'],
        'instagram_caption' => ['label' => 'Instagram caption', 'max' => 1000, 'marketing' => true, 'description' => 'An Instagram post caption with a few relevant hashtags.'],
        'greeting' => ['label' => 'Festival greeting', 'max' => 600, 'marketing' => true, 'description' => 'A warm festival or seasonal greeting to customers.'],
        'google_review_reply' => ['label' => 'Reply to a review', 'max' => 800, 'marketing' => true, 'description' => 'A polite public reply to a customer review (paste the review in the instructions).'],
    ],

    'instructions_max' => 500,

    'queue' => env('AI_QUEUE', 'ai'),
    'tries' => 2,
    'backoff' => [60],

];
