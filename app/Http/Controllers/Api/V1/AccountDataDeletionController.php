<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataDeletionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountDataDeletionController extends Controller
{
    /**
     * Record, rather than immediately execute, a self-service account-data
     * deletion request.  Immediate deletion would be unsafe: connected
     * providers can require token revocation and a deployment can have legal
     * retention duties that need an operator's verified review.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => ['required', 'accepted'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        // This privacy route intentionally runs without the tenant middleware
        // so a user removed from every organisation can still reach it.  The
        // recorded context is therefore derived from the authenticated
        // account's active membership, never from an untrusted request header.
        $organizationId = $this->activeOrganizationIdFor($user);

        $deletionRequest = DB::transaction(function () use ($user, $validated, $organizationId): DataDeletionRequest {
            // Serialize duplicate submissions for this account.  The lock is
            // on the user row so it works without an unsafe partial unique
            // index and remains portable to the SQLite test database.
            User::query()->lockForUpdate()->findOrFail($user->id);

            $existing = DataDeletionRequest::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            if ($existing instanceof DataDeletionRequest) {
                return $existing;
            }

            return DataDeletionRequest::query()->create([
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'status' => 'pending',
                'reason' => $validated['reason'] ?? null,
                'requested_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Data deletion request recorded.',
            'data' => [
                'id' => (string) $deletionRequest->id,
                'status' => $deletionRequest->status,
                'requested_at' => $deletionRequest->requested_at->toIso8601String(),
            ],
        ], 202);
    }

    private function activeOrganizationIdFor(User $user): ?int
    {
        $organizationId = $user->current_organization_id;

        if ($organizationId === null) {
            return null;
        }

        return $user->isMemberOf($organizationId) ? $organizationId : null;
    }
}
