<?php

namespace App\Http\Controllers\Website;

use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Actions\SubmitEnquiry;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Support\SectionCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** The enquiry form of the public website's contact section. */
class EnquiryController extends Controller
{
    /** Hidden field real visitors leave empty. */
    public const HONEYPOT = 'company_website';

    public function __invoke(Request $request, TenantContext $context, SectionCatalog $catalog, SubmitEnquiry $submit): RedirectResponse
    {
        $contact = WebsiteSection::query()->where('type', 'contact')->where('enabled', true)->first();

        abort_unless($context->hasModule('leads') && $contact && ($catalog->resolve('contact', $contact->configuration)['show_form'] ?? false), 404);

        if (filled($request->input(self::HONEYPOT))) {
            Log::info('website.enquiry_honeypot', ['tenant_id' => $context->id()]);

            return back()->with('enquiry_sent', true);
        }

        $submit->handle($request->only(['name', 'phone', 'email', 'interest', 'message']));

        return back()->with('enquiry_sent', true);
    }
}
