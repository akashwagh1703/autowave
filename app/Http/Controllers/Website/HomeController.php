<?php

namespace App\Http\Controllers\Website;

use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Services\WebsiteContent;
use App\Domain\Website\Support\WebsitePreview;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public tenant website: the chosen template renders the tenant's enabled sections (ADR-012,
 * ADR-016). Unpublished websites are only shown through a signed preview link.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, WebsiteContent $content): Response
    {
        abort_unless($context->hasModule('website'), 404);

        $config = WebsiteConfig::query()->with('template')->first();
        abort_if($config === null, 404);

        $previewing = $request->routeIs('tenant.preview');
        abort_if($previewing && ! WebsitePreview::isValid($request, $context->tenant()), 404);
        abort_unless($config->isPublished() || $previewing, 404);

        return Inertia::render('website/Home', [
            ...$content->page($config),
            'preview' => $previewing && ! $config->isPublished(),
            'enquirySent' => (bool) $request->session()->get('enquiry_sent'),
            'bookingConfirmation' => $request->session()->get('booking_confirmation'),
            'orderConfirmation' => $request->session()->get('order_confirmation'),
        ]);
    }
}
