<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\User\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Inbox housekeeping: assignment, open/closed, opt-out, read. */
class ManageConversation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function assign(Conversation $conversation, ?int $tenantUserId): void
    {
        $member = $tenantUserId ? $this->assignableQuery()->find($tenantUserId) : null;

        if ($tenantUserId && ! $member) {
            throw ValidationException::withMessages(['tenant_user_id' => __('Choose a team member who can see the inbox.')]);
        }

        $conversation->forceFill(['assigned_tenant_user_id' => $member?->id])->save();
    }

    /** @return Collection<int, TenantUser> active members who can see the inbox */
    public function assignableMembers(): Collection
    {
        return $this->assignableQuery()->with('user:id,name')->orderBy('id')->get();
    }

    public function setStatus(Conversation $conversation, ConversationStatus $status): void
    {
        $conversation->forceFill([
            'status' => $status,
            'unread_count' => $status === ConversationStatus::Closed ? 0 : $conversation->unread_count,
        ])->save();
    }

    public function setOptOut(Conversation $conversation, bool $optedOut): void
    {
        if ($optedOut === $conversation->isOptedOut()) {
            return;
        }

        $conversation->forceFill(['opted_out_at' => $optedOut ? now() : null])->save();
        $this->audit->log($optedOut ? 'conversation.opted_out' : 'conversation.opted_in', $conversation, ['channel' => $conversation->channel]);
    }

    public function markRead(Conversation $conversation): void
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
    }

    private function assignableQuery(): Builder
    {
        // tenant_users is not tenant-scoped: filter explicitly.
        return TenantUser::query()
            ->where('tenant_id', $this->context->tenant()->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('user', fn (Builder $user) => $user->where('status', UserStatus::Active))
            ->whereHas('roles', fn (Builder $role) => $role->where(fn (Builder $grant) => $grant
                ->where('grants_all', true)
                ->orWhereHas('permissions', fn (Builder $permission) => $permission->where('key', 'conversations.view'))));
    }
}
