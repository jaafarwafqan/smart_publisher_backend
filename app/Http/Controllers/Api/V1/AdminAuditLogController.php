<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexAuditLogsRequest;
use App\Models\PlatformAuditLog;
use Illuminate\Http\JsonResponse;

/**
 * Sprint G (role/permission remediation, 2026-08-09): platform_audit_logs
 * was write-only until now — every super_admin action (and, since this
 * sprint, every organization-scoped action — see PlatformAuditLogger) was
 * recorded, but nothing ever read it back. super_admin only; no
 * organization_id filter restriction, since a platform administrator may
 * legitimately need to review any organization's trail.
 */
class AdminAuditLogController extends Controller
{
    public function index(IndexAuditLogsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = PlatformAuditLog::query()->with(['actor:id,name,email', 'organization:id,name']);

        if ($organizationId = $validated['organization_id'] ?? null) {
            $query->where('organization_id', $organizationId);
        }
        if ($userId = $validated['user_id'] ?? null) {
            $query->where('actor_user_id', $userId);
        }
        if ($action = $validated['action'] ?? null) {
            $query->where('action', $action);
        }
        if ($dateFrom = $validated['date_from'] ?? null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $validated['date_to'] ?? null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $entries = $query->latest('created_at')->paginate($validated['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $entries->getCollection()->map(fn (PlatformAuditLog $entry): array => $this->transform($entry))->values(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    private function transform(PlatformAuditLog $entry): array
    {
        return [
            'id' => $entry->id,
            'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name, 'email' => $entry->actor->email] : null,
            'organization' => $entry->organization ? ['id' => $entry->organization->id, 'name' => $entry->organization->name] : null,
            'action' => $entry->action,
            'auditable_type' => class_basename($entry->auditable_type),
            'auditable_id' => $entry->auditable_id,
            'old_values' => $entry->old_values,
            'new_values' => $entry->new_values,
            'request_id' => $entry->request_id,
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
