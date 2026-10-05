<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Marketing\Models\DemoRequest;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Super Admin → Demo requests: people who asked for a demo on the marketing site, and their follow-up status. */
class DemoRequestController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...DemoRequest::STATUSES])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? DemoRequest::NEW;

        $requests = DemoRequest::query()
            ->with('handler:id,name')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('business_name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")
                ->orWhereLike('city', "%{$search}%")
                ->when(preg_replace('/\D+/', '', $search), fn (Builder $query, string $digits) => $query->orWhereLike('phone', "%{$digits}%"))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $requests->through(fn (DemoRequest $demo) => [
            'id' => $demo->id,
            'name' => $demo->name,
            'phone' => $demo->phone,
            'email' => $demo->email,
            'business_name' => $demo->business_name,
            'industry' => $demo->industry,
            'city' => $demo->city,
            'message' => $demo->message,
            'status' => $demo->status,
            'note' => $demo->note,
            'lead_id' => $demo->lead_id,
            'handled_by' => $demo->handler?->name,
            'handled_at' => $demo->handled_at?->toIso8601String(),
            'created_at' => $demo->created_at->toIso8601String(),
        ]);

        return Inertia::render('admin/marketing/DemoRequests', [
            'requests' => $requests,
            'filters' => ['status' => $status, 'search' => $filters['search'] ?? ''],
            'counts' => DemoRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'appUrl' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function update(Request $request, DemoRequest $demoRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(DemoRequest::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = $demoRequest->only(['status', 'note']);
        $demoRequest->update([
            'status' => $validated['status'],
            'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
            'handled_by_user_id' => $request->user()->getKey(),
            'handled_at' => now(),
        ]);
        $this->audit->log('marketing.demo_request_updated', $demoRequest, ['before' => $before, 'after' => $demoRequest->only(['status', 'note'])]);

        return back()->with('success', __('Demo request from :name saved.', ['name' => $demoRequest->name]));
    }

    public function destroy(DemoRequest $demoRequest): RedirectResponse
    {
        $demoRequest->delete();
        $this->audit->log('marketing.demo_request_deleted', null, ['demo_request_id' => $demoRequest->id, 'business' => $demoRequest->business_name]);

        return back()->with('success', __('Demo request deleted.'));
    }
}
