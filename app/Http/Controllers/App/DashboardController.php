<?php

namespace App\Http\Controllers\App;

use App\Domain\Lead\Services\CrmMetrics;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, CrmMetrics $crmMetrics): Response
    {
        $tenant = $context->tenant()->loadMissing(['businessType:id,code,name', 'primaryDomain']);
        $widgets = $context->setting('dashboard_widgets', []);

        return Inertia::render('business/Dashboard', [
            'workspace' => [
                'name' => $tenant->name,
                'business_type' => $tenant->businessType?->name,
                'website_url' => $tenant->primaryDomain ? '//'.$tenant->primaryDomain->domain.$this->port() : null,
            ],
            'modules' => $context->enabledModules(),
            'engines' => $context->enabledEngines(),
            'widgets' => $widgets,
            'metrics' => $crmMetrics->for($request->user(), $widgets),
        ]);
    }

    private function port(): string
    {
        $port = request()->getPort();

        return in_array($port, [80, 443], true) ? '' : ':'.$port;
    }
}
