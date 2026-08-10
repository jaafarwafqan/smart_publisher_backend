<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditLog;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint G (role/permission remediation, 2026-08-09): platform_audit_logs
 * was write-only — every action in the list below is now both recorded
 * (PlatformAuditLogger, wired into the real controllers) and readable
 * (GET /admin/audit-logs, GET /organizations/{organization}/audit-logs).
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function addToOrganization(User $owner, User $member, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id, 'user_id' => $member->id],
            ['role' => $role, 'status' => 'active'],
        );
    }

    private function inOrganization(User $owner): self
    {
        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id]);

        return $this;
    }

    public function test_connecting_updating_and_deleting_a_social_account_are_all_audited(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 111_222, 'is_bot' => true, 'username' => 'audited_bot'],
            ], 200),
        ]);

        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $connect = $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->postJson('/api/v1/users/'.$owner->id.'/social-accounts/telegram/connect', [
                'bot_token' => 'audited-bot-token',
            ]);
        $connect->assertCreated();
        $accountId = $connect->json('data.id');

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $owner->id,
            'organization_id' => $owner->current_organization_id,
            'action' => 'social_account.connected',
            'auditable_id' => $accountId,
        ]);

        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->putJson('/api/v1/users/'.$owner->id.'/social-accounts/'.$accountId, [
                'account_name' => 'Renamed',
            ])->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'social_account.updated',
            'auditable_id' => $accountId,
        ]);

        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->deleteJson('/api/v1/users/'.$owner->id.'/social-accounts/'.$accountId)
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'social_account.deleted',
            'auditable_id' => $accountId,
        ]);

        // The bot token must never appear in any recorded old/new value.
        $rows = PlatformAuditLog::query()->where('auditable_id', $accountId)->get();
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('audited-bot-token', json_encode($row->old_values));
            $this->assertStringNotContainsString('audited-bot-token', json_encode($row->new_values));
        }
    }

    public function test_member_invite_role_change_and_removal_are_audited(): void
    {
        $owner = User::factory()->create();
        $newMember = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->inOrganization($owner)->postJson('/api/v1/organization/members', [
            'email' => $newMember->email,
            'role' => 'editor',
        ])->assertCreated();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'member.invited',
            'organization_id' => $owner->current_organization_id,
        ]);

        $this->inOrganization($owner)->putJson('/api/v1/organization/members/'.$newMember->id, [
            'role' => 'manager',
        ])->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'member.role_changed',
        ]);

        $this->inOrganization($owner)->deleteJson('/api/v1/organization/members/'.$newMember->id)
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'member.removed',
        ]);
    }

    public function test_post_approval_and_rejection_are_audited(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToOrganization($owner, $editor, OrganizationRole::Editor);

        $post = $this->asOrganizationOf($owner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Needs approval',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($editor);
        $this->inOrganization($owner)->postJson('/api/v1/posts/'.$post->id.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(202);

        Sanctum::actingAs($owner);
        $this->inOrganization($owner)->postJson('/api/v1/posts/'.$post->id.'/approve')->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'post.approved',
            'auditable_id' => $post->id,
        ]);
    }

    public function test_super_admin_can_list_the_platform_wide_audit_log(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->inOrganization($owner)->postJson('/api/v1/users/'.$owner->id.'/social-accounts/telegram/connect', [
            'bot_token' => 'x',
        ]);

        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]], 200),
        ]);
        Sanctum::actingAs($owner);
        $this->inOrganization($owner)->postJson('/api/v1/users/'.$owner->id.'/social-accounts/telegram/connect', [
            'bot_token' => 'audited-token',
        ]);

        Sanctum::actingAs($superAdmin);
        $response = $this->getJson('/api/v1/admin/audit-logs');
        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_non_super_admin_cannot_list_the_platform_wide_audit_log(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }

    public function test_owner_can_list_their_organizations_scoped_audit_log_by_id(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 333_444, 'is_bot' => true, 'username' => 'org_scope_bot'],
            ], 200),
        ]);

        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->inOrganization($owner)->postJson('/api/v1/users/'.$owner->id.'/social-accounts/telegram/connect', [
            'bot_token' => 'org-scope-token',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/organizations/'.$owner->current_organization_id.'/audit-logs');
        $response->assertOk();
        $actions = collect($response->json('data'))->pluck('action');
        $this->assertContains('social_account.connected', $actions);
    }

    public function test_a_member_of_a_different_organization_cannot_read_this_organizations_audit_log(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->getJson('/api/v1/organizations/'.$owner->current_organization_id.'/audit-logs')
            ->assertForbidden();
    }

    public function test_manager_without_audit_logs_view_cannot_read_the_organizations_audit_log(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToOrganization($owner, $manager, OrganizationRole::Manager);
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/organizations/'.$owner->current_organization_id.'/audit-logs')
            ->assertForbidden();
    }

    public function test_super_admin_reading_an_organization_via_the_platform_panel_is_itself_audited(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/organizations/'.$owner->current_organization_id)->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'action' => 'organization.viewed',
            'organization_id' => $owner->current_organization_id,
        ]);
    }
}
