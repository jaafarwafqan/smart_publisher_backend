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
    ): void {
        PlatformAuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }
}
