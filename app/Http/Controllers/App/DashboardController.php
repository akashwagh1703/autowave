<?php

namespace App\Http\Controllers\App;

use App\Domain\Booking\Services\BookingMetrics;
use App\Domain\Commerce\Services\CommerceMetrics;
use App\Domain\Education\Services\EducationMetrics;
use App\Domain\Food\Services\FoodMetrics;
use App\Domain\Lead\Services\CrmMetrics;
use App\Domain\Tenant\Support\GoLiveChecklist;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        CrmMetrics $crmMetrics,
        BookingMetrics $bookingMetrics,
        CommerceMetrics $commerceMetrics,
        EducationMetrics $educationMetrics,
        FoodMetrics $foodMetrics,
        GoLiveChecklist $checklist,
    ): Response {
        $tenant = $context->tenant()->loadMissing(['businessType:id,code,name', 'primaryDomain']);
        $widgets = $context->setting('dashboard_widgets', []);
        $goLive = $checklist->items();

        return Inertia::render('business/Dashboard', [
            'workspace' => [
                'name' => $tenant->name,
                'business_type' => $tenant->businessType?->name,
                'website_url' => $tenant->primaryDomain ? '//'.$tenant->primaryDomain->domain.$this->port() : null,
            ],
            'modules' => $context->enabledModules(),
            'engines' => $context->enabledEngines(),
            'widgets' => $widgets,
            'metrics' => [
                ...$crmMetrics->for($request->user(), $widgets),
                ...self::combineRevenue(
                    $bookingMetrics->for($request->user(), $widgets),
                    $commerceMetrics->for($request->user(), $widgets),
                ),
                ...$educationMetrics->for($request->user(), $widgets),
                ...$foodMetrics->for($request->user(), $widgets),
            ],
            'goLive' => [
                'items' => $goLive,
                'done' => count(array_filter($goLive, fn (array $item) => $item['done'])),
                'total' => count($goLive),
            ],
        ]);
    }

    /**
     * Booking and commerce figures together. When both engines report today's revenue, the widget
     * shows the sum of completed appointments and completed orders.
     *
     * @param  array<string, array<string, mixed>>  $booking
     * @param  array<string, array<string, mixed>>  $commerce
     * @return array<string, array<string, mixed>>
     */
    private static function combineRevenue(array $booking, array $commerce): array
    {
        if (isset($booking['revenue_today'], $commerce['revenue_today'])) {
            $commerce['revenue_today'] = [
                ...$booking['revenue_today'],
                'value' => bcadd((string) $booking['revenue_today']['value'], (string) $commerce['revenue_today']['value'], 2),
                'hint' => 'Completed appointments and orders today',
            ];
        }

        return [...$booking, ...$commerce];
    }

    private function port(): string
    {
        $port = request()->getPort();

        return in_array($port, [80, 443], true) ? '' : ':'.$port;
    }
}
