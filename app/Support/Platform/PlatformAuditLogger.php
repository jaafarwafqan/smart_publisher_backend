<?php

namespace App\Support\Platform;

use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class PlatformAuditLogger
{
    /**
     * Values are supplied deliberately by callers. Passwords, tokens, OAuth
     * secrets, and raw request payloads must never be passed to this logger.
     *
     * $organizationId (Sprint G, role/permission remediation) is null for a
     * genuinely platform-wide event (no single owning organization — e.g. a
     * user's platform role changing) and set for an organization-scoped one
     * (e.g. a social account connected, a member's role changed). request_id/
     * ip_address are captured here automatically from the request rather
     * than requiring every call site to pass them.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        Request $request,
        User $actor,
        string $action,
        string $auditableType,
        ?int $auditableId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $organizationId = null,
    ): void {
        PlatformAuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'organization_id' => $organizationId,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'correlation_id' => $request->attributes->get('correlation_id'),
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
        ]);
    }
}
