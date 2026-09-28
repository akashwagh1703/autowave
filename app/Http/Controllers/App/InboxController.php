<?php

namespace App\Http\Controllers\App;

use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Actions\ManageConversation;
use App\Domain\Messaging\Actions\ReplyToConversation;
use App\Domain\Messaging\Actions\StartConversation;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\MessagingPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The shared inbox: WhatsApp and Instagram conversations (ADR-018). */
class InboxController extends Controller
{
    public const VIEWS = ['open', 'mine', 'unassigned', 'closed', 'all'];

    public function __construct(
        private readonly ChannelResolver $channels,
        private readonly MessagingCompliance $compliance,
        private readonly ManageConversation $manage,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $this->manage->markRead($conversation);

        return $this->render($request, $conversation);
    }

    public function reply(Request $request, Conversation $conversation, ReplyToConversation $reply): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.config('messaging.inbox.reply_max')],
            'client_id' => ['nullable', 'uuid'],
        ]);

        $reply->text($conversation, $validated['body'], $request->user(), $validated['client_id'] ?? null);

        return back();
    }

    public function template(Request $request, Conversation $conversation, ReplyToConversation $reply): RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer'],
            'params' => ['nullable', 'array', 'list', 'max:10'],
            'params.*' => ['nullable', 'string', 'max:500'],
            'client_id' => ['nullable', 'uuid'],
        ]);

        $template = MessageTemplate::query()->find($validated['template_id']);

        if (! $template) {
            return back()->withErrors(['template' => __('Choose an approved template.')]);
        }

        $reply->template($conversation, $template, $validated['params'] ?? [], $request->user(), $validated['client_id'] ?? null);

        return back()->with('success', __('Template sent.'));
    }

    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate(['tenant_user_id' => ['nullable', 'integer']]);
        $this->manage->assign($conversation, $validated['tenant_user_id'] ?? null);

        return back()->with('success', $conversation->assigned_tenant_user_id ? __('Conversation assigned.') : __('Conversation unassigned.'));
    }

    public function status(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(ConversationStatus::class)]]);
        $status = ConversationStatus::from($validated['status']);
        $this->manage->setStatus($conversation, $status);

        return back()->with('success', $status === ConversationStatus::Closed ? __('Conversation closed.') : __('Conversation reopened.'));
    }

    public function optOut(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate(['opted_out' => ['required', 'boolean']]);
        $this->manage->setOptOut($conversation, (bool) $validated['opted_out']);

        return back()->with('success', $validated['opted_out'] ? __('Marked as opted out. Automations and templates will not message this contact.') : __('Opt-out removed.'));
    }

    public function startForCustomer(Customer $customer, StartConversation $start): RedirectResponse
    {
        return to_route('inbox.show', $start->handle(customer: $customer));
    }

    public function startForLead(Lead $lead, StartConversation $start): RedirectResponse
    {
        return to_route('inbox.show', $start->handle(lead: $lead));
    }

    private function render(Request $request, ?Conversation $selected): Response
    {
        $memberId = $this->memberId($request);
        $filters = [
            'view' => in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'open',
            'channel' => in_array($request->query('channel'), ['whatsapp', 'instagram'], true) ? $request->query('channel') : null,
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
        ];

        $query = Conversation::query()->with(['assignee.user:id,name', 'customer:id,tenant_id,name,deleted_at', 'lead:id,tenant_id,name,deleted_at']);

        match ($filters['view']) {
            'open' => $query->where('status', ConversationStatus::Open),
            'mine' => $query->where('status', ConversationStatus::Open)->where('assigned_tenant_user_id', $memberId ?? 0),
            'unassigned' => $query->where('status', ConversationStatus::Open)->whereNull('assigned_tenant_user_id'),
            'closed' => $query->where('status', ConversationStatus::Closed),
            default => null,
        };

        $query->when($filters['channel'], fn (Builder $q, string $channel) => $q->where('channel', $channel));

        if ($filters['search']) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('contact_name', 'ilike', $like)
                ->orWhere('contact_handle', 'like', $like)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'ilike', $like))
                ->orWhereHas('lead', fn (Builder $l) => $l->where('name', 'ilike', $like)));
        }

        $conversations = $query->orderByDesc('last_message_at')->orderByDesc('id')->paginate(config('messaging.inbox.per_page'))->withQueryString();

        $open = Conversation::query()->where('status', ConversationStatus::Open);

        return Inertia::render('business/inbox/Index', [
            'conversations' => CrmPresenter::paginated($conversations, fn (Conversation $conversation) => MessagingPresenter::conversation($conversation)),
            'filters' => $filters,
            'counts' => [
                'open' => (clone $open)->count(),
                'mine' => $memberId ? (clone $open)->where('assigned_tenant_user_id', $memberId)->count() : 0,
                'unassigned' => (clone $open)->whereNull('assigned_tenant_user_id')->count(),
                'unread' => (clone $open)->where('unread_count', '>', 0)->count(),
            ],
            'channels' => collect(['whatsapp', 'instagram'])->mapWithKeys(fn (string $channel) => [$channel => [
                'label' => config("messaging.channels.{$channel}.label"),
                'connected' => $this->channels->connected($channel) !== null,
            ]])->all(),
            'conversation' => $selected ? $this->detail($request, $selected) : null,
            'members' => $request->user()->can('conversations.assign')
                ? $this->manage->assignableMembers()->map(fn (TenantUser $member) => MessagingPresenter::member($member))->values()->all()
                : [],
            'pollSeconds' => (int) config('messaging.inbox.poll_seconds'),
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(Request $request, Conversation $conversation): array
    {
        $conversation->loadMissing(['assignee.user:id,name', 'customer', 'lead']);
        $limit = (int) config('messaging.inbox.thread_limit');

        $messages = $conversation->messages()
            ->with(['outbound:id,tenant_id,status,error,simulated,scheduled_for,sent_by_user_id,automation_run_id', 'outbound.sender:id,name'])
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $windowClosesAt = $this->compliance->windowClosesAt($conversation->channel, $conversation);
        $canReply = $request->user()->can('conversations.reply');

        return [
            ...MessagingPresenter::conversation($conversation),
            'messages' => $messages->take($limit)->reverse()->values()->map(fn ($message) => MessagingPresenter::message($message))->all(),
            'has_older' => $messages->count() > $limit,
            'simulated' => $this->channels->isSimulated($conversation->channel),
            'window' => [
                'enforced' => $this->compliance->hasWindow($conversation->channel),
                'closes_at' => $windowClosesAt?->toIso8601String(),
            ],
            'text_blocked' => $this->compliance->textBlockedReason($conversation->channel, $conversation),
            'template_blocked' => $this->compliance->templateBlockedReason($conversation->channel, $conversation),
            'templates' => $canReply && config("messaging.channels.{$conversation->channel}.templates")
                ? MessageTemplate::query()->approved()->where('channel', $conversation->channel)->orderBy('name')->get()
                    ->map(fn (MessageTemplate $template) => MessagingPresenter::template($template))->all()
                : [],
        ];
    }

    private function memberId(Request $request): ?int
    {
        return TenantUser::query()
            ->where('tenant_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->value('id');
    }
}
