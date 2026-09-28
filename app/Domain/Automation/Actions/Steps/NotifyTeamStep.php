<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Automation\Support\TemplateRenderer;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\User\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * Emails team members: the lead's assignee (falling back to the owners when the lead is
 * unassigned or the subject is not a lead) or the owners.
 */
class NotifyTeamStep implements StepAction
{
    public const RECIPIENTS = ['assignee', 'owners'];

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly TemplateRenderer $renderer,
        private readonly TenantContext $context,
    ) {}

    public function rules(): array
    {
        return [
            'recipients' => ['required', Rule::in(self::RECIPIENTS)],
            'subject' => ['required', 'string', 'max:'.config('automation.limits.subject')],
            'message' => ['required', 'string', 'max:'.config('automation.limits.message')],
        ];
    }

    public function normalize(array $config): array
    {
        return ['recipients' => $config['recipients'], 'subject' => trim($config['subject']), 'message' => trim($config['message'])];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $members = $this->recipients($context, $config['recipients']);

        if ($members->isEmpty()) {
            return ActionResult::skipped('There is nobody on the team to notify.');
        }

        $subject = $this->renderer->render($config['subject'], $context->subject);
        $body = $this->renderer->render($config['message'], $context->subject);

        foreach ($members as $member) {
            $this->messaging->queue([
                'channel' => 'email',
                'recipient' => $member->user->email,
                'recipient_name' => $member->user->name,
                'subject' => $subject,
                'body' => $body,
                'idempotency_key' => $context->idempotencyKey.':member:'.$member->id,
                'tenant_user_id' => $member->id,
                'automation_run_id' => $context->run->id,
            ]);
        }

        return ActionResult::completed('Notified '.$members->map(fn (TenantUser $member) => $member->user->name)->join(', ', ' and ').'.', [
            'members' => $members->modelKeys(),
        ]);
    }

    /** @return Collection<int, TenantUser> */
    private function recipients(ActionContext $context, string $recipients): Collection
    {
        $assignee = $context->subject->lead?->assignee;

        if ($recipients === 'assignee' && $assignee && $this->active()->whereKey($assignee->id)->exists()) {
            return new Collection([$assignee->loadMissing('user')]);
        }

        return $this->active()
            ->whereHas('roles', fn (Builder $role) => $role->where('grants_all', true))
            ->with('user')
            ->orderBy('id')
            ->get();
    }

    private function active(): Builder
    {
        return TenantUser::query()
            ->where('tenant_id', $this->context->tenant()->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('user', fn (Builder $user) => $user->where('status', UserStatus::Active));
    }
}
