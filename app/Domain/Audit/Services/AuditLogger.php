<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  never include secrets or tokens
     */
    public function log(string $action, ?Model $subject = null, array $metadata = [], ?int $tenantId = null): AuditLog
    {
        return AuditLog::query()->create([
            'tenant_id' => $tenantId ?? $this->context->id(),
            'user_id' => $this->request->user()?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
        ]);
    }
}
