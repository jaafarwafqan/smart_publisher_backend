<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaAttachment;
use App\Models\Post;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 4 (Commercial SaaS): the "download my data" counterpart to
 * AccountDataDeletionController — most privacy regimes (GDPR Art. 15, etc.)
 * pair a right to erasure with a right to access, and the deletion-request
 * endpoint existed with no way to actually get a copy of what's being
 * deleted. Same reasoning as that controller: not tenant-gated, so a user
 * removed from every organization can still reach it, and it deliberately
 * spans every organization the user has ever belonged to (not just the
 * currently active one) via withoutGlobalScope(OrganizationScope::class) —
 * this is "my data across my whole account," not a single-tenant view.
 *
 * Deliberately excludes access_token/refresh_token and any other secret —
 * selected columns are an explicit safe allow-list, never a raw toArray()
 * of a model whose encrypted casts would otherwise decrypt them straight
 * into the export.
 */
class AccountDataExportController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $organizations = $user->organizations()
            ->get(['organizations.id', 'organizations.name', 'organizations.slug', 'organizations.created_at']);

        $posts = Post::withoutGlobalScope(OrganizationScope::class)
            ->where('user_id', $user->id)
            ->get(['id', 'organization_id', 'title', 'content', 'status', 'scheduled_at', 'published_at', 'created_at']);

        $socialAccounts = SocialAccount::withoutGlobalScope(OrganizationScope::class)
            ->where('user_id', $user->id)
            ->get(['id', 'organization_id', 'provider', 'provider_account_id', 'account_name', 'account_username', 'status', 'is_active', 'last_synced_at', 'created_at']);

        $mediaAttachments = MediaAttachment::withoutGlobalScope(OrganizationScope::class)
            ->where('user_id', $user->id)
            ->get(['id', 'organization_id', 'type', 'path', 'mime_type', 'size', 'created_at']);

        return response()->json([
            'message' => 'Data export generated.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    // Both are Carbon instances at runtime (email_verified_at
                    // via an explicit cast, created_at via Eloquent's
                    // built-in timestamp casting) — Larastan doesn't see
                    // either through the casts() method's return type, so
                    // this stays correct under both static and runtime typing.
                    'email_verified_at' => $user->email_verified_at !== null ? Carbon::parse($user->email_verified_at)->toIso8601String() : null,
                    'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                    'created_at' => $user->created_at !== null ? Carbon::parse($user->created_at)->toIso8601String() : null,
                ],
                'organizations' => $organizations,
                'posts' => $posts,
                'social_accounts' => $socialAccounts,
                'media_attachments' => $mediaAttachments,
                'exported_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
