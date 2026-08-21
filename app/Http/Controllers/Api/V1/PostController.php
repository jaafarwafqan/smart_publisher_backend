<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\PublishNowPostRequest;
use App\Http\Requests\Post\RejectPostRequest;
use App\Http\Requests\Post\SchedulePostRequest;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialPage;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\NotificationService;
use App\Services\Publishing\PrePublishValidationService;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Billing\QuotaGates;
use App\Support\Content\RichContentSanitizer;
use App\Support\Platform\PlatformAuditLogger;
use App\Support\Publishing\AttemptStateMachine;
use App\Support\Publishing\ClosedBetaPublishingGate;
use App\Support\Publishing\PostStateMachine;
use App\Support\Publishing\PublicationBatchCoordinator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $query = Post::query()->with([
            'user:id,name,email',
            'branch:id,name,code',
            'mediaAttachments',
            'socialPages.socialAccount:id,provider',
            'approvedBy:id,name',
        ]);

        // The tenant scope limits the organization, but it deliberately does
        // not distinguish an editor's own posts from everyone else's. That
        // second boundary belongs here, where the organization membership's
        // permission template is available. Do not rely on Flutter hiding
        // rows: an editor must never receive another member's post at all.
        if ($user->hasOrganizationPermission($this->currentOrganizationId($request), OrganizationPermission::PostsViewAll)) {
            // Managers, admins, owners and viewers can see the whole active
            // organization according to the membership permission template.
        } elseif ($user->hasOrganizationPermission($this->currentOrganizationId($request), OrganizationPermission::PostsViewOwn)) {
            $query->where('user_id', $user->id);
        } else {
            abort(403, 'You do not have permission to view posts in this organization.');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        // Sprint F (role/permission remediation): lets the Approvals screen
        // ask for exactly the pending queue (?approval_status=pending)
        // instead of fetching every post and filtering client-side — same
        // pattern as the existing `status` filter above.
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->string('approval_status')->toString());
        }

        $posts = $query->latest()->paginate(20);

        return response()->json([
            'data' => PostResource::collection($posts->getCollection())->resolve(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorizeCapability($user, $this->currentOrganizationId($request), OrganizationPermission::PostsCreate);

        $validated = $request->validated();
        if (isset($validated['meta']) && is_array($validated['meta'])) {
            $validated['meta'] = app(RichContentSanitizer::class)->sanitizeMeta($validated['meta']);
        }

        // A retried create request — the client's own automatic retry on a
        // dropped response, or an offline-outbox entry replayed after the
        // original response never made it back — carries the same
        // Idempotency-Key every time (the client's stable local draft id).
        // Recognize it and hand back the original post instead of silently
        // minting a duplicate draft. `Post::query()` is already
        // organization-scoped by BelongsToOrganization's global scope, so
        // this can never match another org's row.
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey) {
            $existing = Post::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return response()->json([
                    'message' => 'Post already created (idempotent replay).',
                    'data' => (new PostResource($existing->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
                ]);
            }
        }

        try {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'branch_id' => $validated['branch_id'] ?? null,
                'title' => $validated['title'],
                'content' => $validated['content'] ?? null,
                'status' => 'draft',
                'meta' => $validated['meta'] ?? [],
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            // Two identical retries raced each other between the check
            // above and this insert — the unique index caught the loser.
            // Not a real failure: return the winner's row, same as if we'd
            // seen it on the first check. Gated on SQLSTATE 23000 (integrity
            // constraint violation) specifically, not just a message
            // containing "idempotency_key" — a schema/migration mismatch
            // (e.g. the column genuinely doesn't exist yet in an
            // environment that hasn't run the latest migrations) also
            // mentions the column name in its error but has a different
            // SQLSTATE, and must surface as the real 500 it is instead of
            // being misclassified into a confusing secondary query error.
            if ($idempotencyKey && $e->getCode() === '23000' && str_contains($e->getMessage(), 'idempotency_key')) {
                $post = Post::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

                return response()->json([
                    'message' => 'Post already created (idempotent replay).',
                    'data' => (new PostResource($post->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
                ]);
            }

            throw $e;
        }

        if (array_key_exists('target_page_ids', $validated)) {
            $post->socialPages()->sync($this->ownedPageIds($validated['target_page_ids'] ?? [], $user->id));
        }

        app(DashboardCacheService::class)->invalidateDashboard($user->id);

        return response()->json([
            'message' => 'Post created as draft.',
            'data' => (new PostResource($post->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ], 201);
    }

    public function show(Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        return response()->json([
            'data' => (new PostResource($post->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'publicationAttempts', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ]);
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        $this->authorize('update', $post);

        $validated = $request->validated();
        if (isset($validated['meta']) && is_array($validated['meta'])) {
            $validated['meta'] = app(RichContentSanitizer::class)->sanitizeMeta($validated['meta']);
        }

        $post->update(collect($validated)->except('target_page_ids')->all());

        if (array_key_exists('target_page_ids', $validated)) {
            $post->socialPages()->sync($this->ownedPageIds($validated['target_page_ids'] ?? [], (int) $post->user_id));
        }

        app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);

        return response()->json([
            'message' => 'Post updated successfully.',
            'data' => (new PostResource($post->fresh()->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ]);
    }

    public function destroy(Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        $userId = (int) $post->user_id;

        $post->delete();

        app(DashboardCacheService::class)->invalidateDashboard($userId);

        return response()->json([
            'message' => 'Post deleted successfully.',
        ]);
    }

    public function schedule(SchedulePostRequest $request, Post $post): JsonResponse
    {
        $this->authorize('publish', $post);

        $validated = $request->validated();
        app(ClosedBetaPublishingGate::class)->assertPostTargetsAllowed($post);
        app(ClosedBetaPublishingGate::class)->assertMediaSupportedByPostTargets($post);

        if (! $this->canPublishDirectly($request, $post)) {
            $post->update([
                'approval_status' => 'pending',
                'approval_requested_action' => 'schedule',
                'approval_requested_scheduled_at' => $validated['scheduled_at'],
                'approval_note' => null,
            ]);

            app(NotificationService::class)->approvalRequested($post);
            app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);

            return response()->json([
                'message' => 'Submitted for approval.',
                'data' => (new PostResource($post->fresh()->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
            ], 202);
        }

        $this->assertPublishQuotaAvailable($this->currentOrganizationId($request));
        $this->doSchedule($post, $validated['scheduled_at']);

        app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);

        return response()->json([
            'message' => 'Post scheduled successfully.',
            'data' => (new PostResource($post->fresh()->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ]);
    }

    public function publishNow(PublishNowPostRequest $request, Post $post): JsonResponse
    {
        $this->authorize('publish', $post);

        $validated = $request->validated();

        $pageIds = ! empty($validated['social_page_ids'])
            ? $this->ownedPageIds($validated['social_page_ids'], (int) $post->user_id, 'social_page_ids')
            : $post->socialPages()->where('can_publish', true)->where('status', 'valid')->pluck('social_pages.id')->all();

        if (empty($pageIds)) {
            return response()->json([
                'message' => 'No usable pages selected for publishing.',
            ], 422);
        }

        $this->assertSelectedPagesAllowed($post, $pageIds);
        app(PrePublishValidationService::class)->assertNoBlockingErrors($post, $pageIds);

        if (! $this->canPublishDirectly($request, $post)) {
            $post->update([
                'approval_status' => 'pending',
                'approval_requested_action' => 'publish_now',
                'meta' => array_merge($post->meta ?? [], ['_pending_publish_page_ids' => $pageIds]),
                'approval_note' => null,
            ]);

            app(NotificationService::class)->approvalRequested($post);
            app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);

            return response()->json([
                'message' => 'Submitted for approval.',
                'data' => (new PostResource($post->fresh()))->resolve(),
            ], 202);
        }

        $this->assertPublishQuotaAvailable($this->currentOrganizationId($request));
        $jobsCount = $this->doPublishNow($post, $pageIds);

        app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);

        return response()->json([
            'message' => 'Publish jobs dispatched successfully.',
            'jobs_count' => $jobsCount,
        ]);
    }

    /**
     * Sprint 2: reviewing/approving a pending post is never tied to
     * ownership (see PostPolicy::approve). Approving performs whatever
     * action was originally requested (schedule or publish-now) using the
     * data captured at request time — a later re-run of the same page
     * selection isn't re-validated against ownership here since it was
     * already validated (and can't have changed — pages are immutable once
     * attached without going through update(), which re-validates).
     */
    public function approve(Request $request, Post $post, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorize('approve', $post);
        app(OrganizationEntitlements::class)->assertFeatureEnabled(
            (int) $post->organization_id,
            QuotaGates::FEATURE_APPROVAL_WORKFLOW,
            'Approval workflows are not available on your organization\'s current plan.',
        );

        if (! $post->isPendingApproval()) {
            return response()->json(['message' => 'This post has no pending approval request.'], 422);
        }

        $requestedAction = $post->approval_requested_action;
        $metadata = is_array($post->meta) ? $post->meta : [];
        $pendingPageIds = $metadata['_pending_publish_page_ids'] ?? [];
        $pageIds = is_array($pendingPageIds) ? $pendingPageIds : [];

        // Validate production provider and target-kind eligibility before an
        // approval mutates state or dispatches a worker job.
        if ($requestedAction === 'schedule') {
            app(ClosedBetaPublishingGate::class)->assertPostTargetsAllowed($post);
            app(ClosedBetaPublishingGate::class)->assertMediaSupportedByPostTargets($post);
        } elseif ($requestedAction === 'publish_now') {
            $this->assertSelectedPagesAllowed($post, $pageIds);
        }

        // Re-check under a row lock rather than trusting the earlier read:
        // two concurrent approve/reject requests for the same post could
        // otherwise both observe approval_status='pending' and both "win",
        // one silently overwriting the other's decision (and dispatching a
        // publish for a post the other request just rejected). Locking here
        // serializes the transition — the loser sees the row already
        // settled and is rejected with the same response a legitimate
        // second call to an already-decided post would get. Mirrors the
        // lockForUpdate pattern already used in doPublishNow() below.
        $claimed = DB::transaction(function () use ($post, $request) {
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (! $lockedPost->isPendingApproval()) {
                return false;
            }

            $lockedPost->update([
                'approval_status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return true;
        });

        if (! $claimed) {
            return response()->json(['message' => 'This post has no pending approval request.'], 422);
        }

        if ($requestedAction === 'schedule') {
            $this->doSchedule($post, $post->approval_requested_scheduled_at);
        } elseif ($requestedAction === 'publish_now') {
            $this->doPublishNow($post, $pageIds);
            $post->update(['meta' => collect($metadata)->except('_pending_publish_page_ids')->all()]);
        }

        app(NotificationService::class)->approvalApproved($post, $request->user());
        app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);

        $audit->record(
            $request,
            $request->user(),
            'post.approved',
            Post::class,
            $post->id,
            ['approval_status' => 'pending'],
            ['approval_status' => 'approved', 'requested_action' => $requestedAction],
            (int) $post->organization_id,
        );

        return response()->json([
            'message' => 'Post approved.',
            'data' => (new PostResource($post->fresh()->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ]);
    }

    public function reject(RejectPostRequest $request, Post $post, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorize('approve', $post);
        app(OrganizationEntitlements::class)->assertFeatureEnabled(
            (int) $post->organization_id,
            QuotaGates::FEATURE_APPROVAL_WORKFLOW,
            'Approval workflows are not available on your organization\'s current plan.',
        );

        if (! $post->isPendingApproval()) {
            return response()->json(['message' => 'This post has no pending approval request.'], 422);
        }

        $validated = $request->validated();

        // Same atomic-claim pattern as approve() — see its comment.
        $claimed = DB::transaction(function () use ($post, $request, $validated) {
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (! $lockedPost->isPendingApproval()) {
                return false;
            }

            $lockedPost->update([
                'approval_status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_note' => $validated['note'] ?? null,
                'meta' => collect($lockedPost->meta ?? [])->except('_pending_publish_page_ids')->all(),
            ]);

            return true;
        });

        if (! $claimed) {
            return response()->json(['message' => 'This post has no pending approval request.'], 422);
        }

        app(NotificationService::class)->approvalRejected($post, $request->user(), $validated['note'] ?? null);

        $audit->record(
            $request,
            $request->user(),
            'post.rejected',
            Post::class,
            $post->id,
            ['approval_status' => 'pending'],
            ['approval_status' => 'rejected', 'note' => $validated['note'] ?? null],
            (int) $post->organization_id,
        );

        return response()->json([
            'message' => 'Post rejected.',
            'data' => (new PostResource($post->fresh()->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ]);
    }

    /**
     * A role that holds posts.publish directly, OR is the post's own owner
     * and holds posts.update_own with no separate publish gate (covered by
     * PostPolicy::publish already granting the *request*) — this method
     * specifically answers "does it execute immediately, or wait for
     * approval." Only the organization-role PostsPublish grant (or the
     * legacy Spatie posts.publish permission) bypasses approval; owning the
     * post is never enough on its own once an organization is involved,
     * since that's exactly the editor case this workflow exists for.
     */
    private function canPublishDirectly(Request $request, Post $post): bool
    {
        return $this->authenticatedUser($request)->hasOrganizationPermission(
            $post->organization_id,
            OrganizationPermission::PostsPublish,
        );
    }

    private function doSchedule(Post $post, $scheduledAt): void
    {
        app(ClosedBetaPublishingGate::class)->assertPostTargetsAllowed($post);
        app(ClosedBetaPublishingGate::class)->assertMediaSupportedByPostTargets($post);

        app(PostStateMachine::class)->transition($post, 'scheduled', [
            'scheduled_at' => $scheduledAt,
            'publish_batch_key' => (string) Str::uuid(),
            'failed_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * @param  array<int, int>  $pageIds
     */
    private function doPublishNow(Post $post, array $pageIds): int
    {
        $pages = SocialPage::query()
            ->whereIn('id', $pageIds)
            ->where('can_publish', true)
            ->where('status', 'valid')
            ->with('socialAccount')
            ->get(['social_pages.id', 'social_pages.social_account_id', 'social_pages.kind']);

        if ($pages->isEmpty()) {
            return 0;
        }

        app(ClosedBetaPublishingGate::class)->assertPagesAllowed($pages);
        app(ClosedBetaPublishingGate::class)->assertMediaSupportedByTargets($post, $pages);

        // Transitions straight to 'publishing' — the same intermediate
        // state ProcessScheduledPostsJob uses before dispatching — rather
        // than 'scheduled', so immediate and scheduled publishing share the
        // identical trusted path into PublishPostJob. Previously this went
        // to 'scheduled' and skipped 'publishing' entirely, which is
        // exactly the inconsistency PostStateMachine's stricter transition
        // map caught: PublishPostJob only ever transitions 'publishing' ->
        // 'published', so the old code would have thrown here as soon as
        // the state machine was enforced.
        $batchKey = (string) Str::uuid();
        $attempts = DB::transaction(function () use ($post, $pages, $batchKey) {
            // Re-read under a row lock rather than trusting $post's
            // possibly-stale in-memory status: two concurrent publishNow
            // requests for the same post could otherwise both observe
            // status='draft'/'scheduled', both pass canTransition(), and
            // both dispatch a full batch of publish jobs. Locking here
            // serializes the two transactions; the second one sees the
            // first's already-'publishing' status and is treated as a
            // duplicate call rather than starting a second batch.
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ($lockedPost->status === 'publishing') {
                return collect();
            }

            app(PostStateMachine::class)->transition($lockedPost, 'publishing', [
                'scheduled_at' => now(),
                'publish_batch_key' => $batchKey,
                'failed_at' => null,
                'last_error' => null,
            ]);

            return app(PublicationBatchCoordinator::class)->createPendingAttempts($lockedPost, $pages, $batchKey);
        });

        foreach ($attempts as $attempt) {
            PublishPostJob::dispatch(
                $post->id,
                (int) $attempt->social_page_id,
                $batchKey,
                (int) $post->organization_id,
                (int) $attempt->id,
            );
        }

        return $attempts->count();
    }

    /**
     * CTO audit P0-3: `target_page_ids`/`social_page_ids` previously only
     * validated `exists:social_pages,id` — any authenticated user could
     * attach or publish through a SocialPage owned by a different user's
     * connected account. Restricts the submitted ids to pages whose
     * SocialAccount actually belongs to $ownerId, and fails loudly (422) if
     * any requested id doesn't belong to them, rather than silently dropping
     * it (which would look like a successful attach that quietly no-opped).
     *
     * @param  array<int, int>  $requestedIds
     * @return array<int, int>
     */
    private function ownedPageIds(array $requestedIds, int $ownerId, string $field = 'target_page_ids'): array
    {
        if (empty($requestedIds)) {
            return [];
        }

        $owned = SocialPage::query()
            ->whereIn('id', $requestedIds)
            ->whereHas('socialAccount', fn ($query) => $query->where('user_id', $ownerId))
            ->pluck('id')
            ->all();

        $notOwned = array_diff($requestedIds, $owned);
        if (! empty($notOwned)) {
            throw ValidationException::withMessages([
                $field => ['You do not own one or more of the selected pages: '.implode(', ', $notOwned).'.'],
            ]);
        }

        return $owned;
    }

    /**
     * @param  array<int, int>  $pageIds
     */
    private function assertSelectedPagesAllowed(Post $post, array $pageIds): void
    {
        $pages = SocialPage::query()
            ->whereIn('id', $pageIds)
            ->with('socialAccount')
            ->get(['social_pages.id', 'social_pages.social_account_id', 'social_pages.kind']);

        app(ClosedBetaPublishingGate::class)->assertPagesAllowed($pages);
        app(ClosedBetaPublishingGate::class)->assertMediaSupportedByTargets($post, $pages);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }

    private function currentOrganizationId(Request $request): int
    {
        // ResolveTenantContext already validates the X-Organization-Id
        // selection against active membership. Reusing that exact value keeps
        // authorization aligned with the tenant scope rather than falling
        // back to the user's stale persisted organization selection.
        return app(TenantContext::class)->get();
    }

    /**
     * Sprint 4 (Commercial SaaS): mirrors the same
     * OrganizationEntitlements::hasCapacityFor() pattern already wired in
     * OrganizationMembershipController. Fails CLOSED (zero capacity) for
     * any organization with no active subscription — see
     * OrganizationEntitlements' own docblock; this is not a no-op.
     * Counts posts that have actually consumed a schedule/publish action
     * this calendar month (not drafts, and not posts still pending
     * approval — those haven't used the quota yet).
     */
    private function assertPublishQuotaAvailable(int $organizationId): void
    {
        $usedThisMonth = Post::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['scheduled', 'publishing', 'published'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if (! app(OrganizationEntitlements::class)->hasCapacityFor($organizationId, 'max_scheduled_posts_per_month', $usedThisMonth)) {
            throw ValidationException::withMessages([
                'post' => ['Your organization has reached its scheduled/published post limit for the current plan this month.'],
            ]);
        }
    }

    private function authorizeCapability(User $user, int $organizationId, OrganizationPermission $permission): void
    {
        if (! $user->hasOrganizationPermission($organizationId, $permission)) {
            abort(403, 'You do not have permission to perform this action in this organization.');
        }
    }

    public function markDraft(Post $post): JsonResponse
    {
        $this->authorize('update', $post);

        app(PostStateMachine::class)->transition($post, 'draft', [
            'scheduled_at' => null,
            'published_at' => null,
            'failed_at' => null,
            'last_error' => null,
            'publish_batch_key' => null,
            'approval_status' => null,
            'approval_requested_action' => null,
            'approval_requested_scheduled_at' => null,
        ]);

        app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);

        return response()->json([
            'message' => 'Post moved to draft.',
            'data' => (new PostResource($post->fresh()->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ]);
    }

    /**
     * Sprint 1 (Publishing Recovery): cancelling a 'scheduled' post is
     * always safe (no attempts exist yet). Cancelling a 'publishing' post
     * only succeeds when every one of its batch's attempts is still
     * 'pending' — once any attempt has been claimed by a worker, a real
     * provider call may already be in flight or complete, and there is no
     * safe way to un-publish that, so this fails closed (409) rather than
     * pretending to cancel something already underway.
     */
    public function cancel(Post $post): JsonResponse
    {
        $this->authorize('publish', $post);

        if (! in_array($post->status, ['scheduled', 'publishing'], true)) {
            return response()->json([
                'message' => 'Only a scheduled or in-progress post can be cancelled.',
            ], 409);
        }

        if (! $this->doCancel($post)) {
            return response()->json([
                'message' => 'Cannot cancel: publishing has already started for one or more targets.',
            ], 409);
        }

        app(DashboardCacheService::class)->invalidateDashboard((int) $post->user_id);
        app(NotificationService::class)->publicationCancelled($post->fresh());

        return response()->json([
            'message' => 'Post cancelled.',
            'data' => (new PostResource($post->fresh()->load(['user:id,name,email', 'branch:id,name,code', 'mediaAttachments', 'socialPages.socialAccount:id,provider', 'approvedBy:id,name'])))->resolve(),
        ]);
    }

    /**
     * Row-locks the post (and, for 'publishing', every attempt in its
     * current batch) inside one transaction so a worker racing to claim an
     * attempt at the same moment can't leave the post 'cancelled' while a
     * provider call still completes underneath it — the same
     * lock-then-recheck pattern doPublishNow() already uses for its own
     * concurrent-request race.
     */
    private function doCancel(Post $post): bool
    {
        return DB::transaction(function () use ($post): bool {
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedPost->status, ['scheduled', 'publishing'], true)) {
                return false;
            }

            if ($lockedPost->status === 'scheduled') {
                app(PostStateMachine::class)->transition($lockedPost, 'cancelled', [
                    'scheduled_at' => null,
                ]);

                return true;
            }

            $batchKey = $lockedPost->publish_batch_key;
            $attempts = $batchKey !== null
                ? PostPublicationAttempt::query()
                    ->where('post_id', $lockedPost->id)
                    ->where('publish_batch_key', $batchKey)
                    ->lockForUpdate()
                    ->get()
                : collect();

            if ($attempts->contains(fn (PostPublicationAttempt $attempt): bool => $attempt->status !== 'pending')) {
                return false;
            }

            $stateMachine = app(AttemptStateMachine::class);
            $attempts->each(fn (PostPublicationAttempt $attempt) => $stateMachine->transition($attempt, 'cancelled'));

            app(PostStateMachine::class)->transition($lockedPost, 'cancelled', [
                'publish_batch_key' => null,
            ]);

            return true;
        });
    }
}
