<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\PublishPostJob;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 2 architectural decision on job-time permission re-checking (see
 * PublishPostJob::handle()'s docblock): approval is an audited delegation,
 * not a live permission snapshot, EXCEPT for one thing that is re-verified
 * — the author must still be a member of the organization at execution
 * time. This is the regression test for that one check.
 */
class PublishPostJobMembershipCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_job_fails_gracefully_if_the_author_was_removed_from_the_organization(): void
    {
        $orgOwner = User::factory()->create();
        $author = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $orgOwner->current_organization_id,
            'user_id' => $author->id,
            'role' => 'editor',
            'status' => 'active',
        ]);

        [$post, $page] = $this->asOrganizationOf($orgOwner, function () use ($author) {
            $account = SocialAccount::query()->create([
                'user_id' => $author->id,
                'provider' => 'instagram',
                'provider_account_id' => 'ig-membership-check',
                'access_token' => 'mock-token',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'ig-page-membership-check',
                'name' => 'Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);
            $post = Post::query()->create([
                'user_id' => $author->id,
                'title' => 'Approved before removal',
                'content' => 'Some content.',
                'status' => 'publishing',
                'scheduled_at' => now(),
                'publish_batch_key' => (string) Str::uuid(),
            ]);

            return [$post, $page];
        });

        // Membership removed AFTER approval/scheduling but BEFORE the job runs.
        OrganizationMembership::query()
            ->where('organization_id', $orgOwner->current_organization_id)
            ->where('user_id', $author->id)
            ->delete();

        (new PublishPostJob($post->id, $page->id, $post->publish_batch_key, $orgOwner->current_organization_id))
            ->handle(app(PublishEngineService::class));

        $post->refresh();
        $this->assertSame('failed', $post->status);
        $this->assertSame('Post author is no longer a member of this organization.', $post->last_error);
    }

    /**
     * Sprint F (role/permission remediation, 2026-08-09): the job now also
     * re-verifies the actual capability that made the publish legal, not
     * just membership. A direct publish (no approval) is authorized by the
     * author's own posts.publish grant — revoked here by demoting the
     * author from manager to editor after scheduling but before the worker
     * runs.
     */
    public function test_publish_job_blocks_a_direct_publish_when_the_authors_publish_permission_is_revoked_before_execution(): void
    {
        $orgOwner = User::factory()->create();
        $author = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $orgOwner->current_organization_id,
            'user_id' => $author->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        [$post, $page] = $this->asOrganizationOf($orgOwner, function () use ($author) {
            $account = SocialAccount::query()->create([
                'user_id' => $author->id,
                'provider' => 'instagram',
                'provider_account_id' => 'ig-publish-revoked-check',
                'access_token' => 'mock-token',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'ig-page-publish-revoked-check',
                'name' => 'Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);
            $post = Post::query()->create([
                'user_id' => $author->id,
                'title' => 'Direct publish, demoted before execution',
                'content' => 'Some content.',
                'status' => 'publishing',
                'scheduled_at' => now(),
                'publish_batch_key' => (string) Str::uuid(),
            ]);

            return [$post, $page];
        });

        // Demoted from manager (holds posts.publish) to editor (does not)
        // AFTER the direct-publish request was accepted, BEFORE the worker runs.
        $membership->update(['role' => 'editor']);

        (new PublishPostJob($post->id, $page->id, $post->publish_batch_key, $orgOwner->current_organization_id))
            ->handle(app(PublishEngineService::class));

        $post->refresh();
        $this->assertSame('failed', $post->status);
        $this->assertSame('Publishing authorization was revoked before this post could be published.', $post->last_error);
    }

    /**
     * Same principle, for an approved post: authorized by the recorded
     * approver's posts.approve grant, not the author's own capability.
     * Revoking the approver's role after approval but before execution must
     * block the publish, even though the author's own role never changed.
     */
    public function test_publish_job_blocks_an_approved_post_when_the_approvers_approve_permission_is_revoked_before_execution(): void
    {
        $orgOwner = User::factory()->create();
        $author = User::factory()->create();
        $approver = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $orgOwner->current_organization_id,
            'user_id' => $author->id,
            'role' => 'editor',
            'status' => 'active',
        ]);
        $approverMembership = OrganizationMembership::query()->create([
            'organization_id' => $orgOwner->current_organization_id,
            'user_id' => $approver->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        [$post, $page] = $this->asOrganizationOf($orgOwner, function () use ($author, $approver) {
            $account = SocialAccount::query()->create([
                'user_id' => $author->id,
                'provider' => 'instagram',
                'provider_account_id' => 'ig-approver-revoked-check',
                'access_token' => 'mock-token',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'ig-page-approver-revoked-check',
                'name' => 'Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);
            $post = Post::query()->create([
                'user_id' => $author->id,
                'title' => 'Approved, approver demoted before execution',
                'content' => 'Some content.',
                'status' => 'publishing',
                'scheduled_at' => now(),
                'publish_batch_key' => (string) Str::uuid(),
                'approval_status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return [$post, $page];
        });

        // Approver demoted from manager (holds posts.approve) to viewer
        // (does not) AFTER approving, BEFORE the worker runs.
        $approverMembership->update(['role' => 'viewer']);

        (new PublishPostJob($post->id, $page->id, $post->publish_batch_key, $orgOwner->current_organization_id))
            ->handle(app(PublishEngineService::class));

        $post->refresh();
        $this->assertSame('failed', $post->status);
        $this->assertSame('Publishing authorization was revoked before this post could be published.', $post->last_error);
    }
}
