<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SocialAccountOrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_in_the_current_organization_can_manage_a_teammates_account_and_pages(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 44_055, 'is_bot' => true, 'username' => 'organization_manager_bot'],
            ], 200),
        ]);

        $organizationOwner = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToOrganization($organizationOwner, $manager, OrganizationRole::Manager);

        $account = $this->socialAccountFor($organizationOwner, $organizationOwner, 'manager-existing');
        [$firstPage, $secondPage] = $this->pagesFor($organizationOwner, $account);

        Sanctum::actingAs($manager);

        $this->inOrganization($organizationOwner)
            ->getJson('/api/v1/users/'.$organizationOwner->id.'/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $account->id);

        $this->inOrganization($organizationOwner)
            ->putJson('/api/v1/users/'.$organizationOwner->id.'/social-accounts/'.$account->id, [
                'account_name' => 'Managed by the organization manager',
            ])
            ->assertOk()
            ->assertJsonPath('data.account_name', 'Managed by the organization manager');

        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$organizationOwner->id.'/social-accounts/'.$account->id.'/pages/select', [
                'page_ids' => [$firstPage->id],
            ])
            ->assertOk();

        $this->assertTrue($firstPage->fresh()->is_selected);
        $this->assertFalse($secondPage->fresh()->is_selected);

        // Sprint C (role/permission remediation): the generic store()
        // endpoint used to sit here (manager POSTs a raw provider_account_id
        // + no real token and gets a "connected" account back) — removed
        // entirely as an unverified-account creation path. Manager's real
        // create capability is proven below via the two remaining, actually
        // provider-verified connection paths: OAuth authorize and Telegram
        // bot connect.
        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$organizationOwner->id.'/social-accounts/authorize', [
                'provider' => 'facebook',
                'redirect_uri' => 'smartpublisher://oauth/callback',
            ])
            ->assertOk();

        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$organizationOwner->id.'/social-accounts/telegram/connect', [
                'bot_token' => '123:manager-connects-for-team',
            ])
            ->assertCreated()
            ->assertJsonPath('data.provider_account_id', '44055');
    }

    public function test_editor_can_view_but_cannot_manage_or_connect_social_accounts(): void
    {
        $organizationOwner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToOrganization($organizationOwner, $editor, OrganizationRole::Editor);

        $account = $this->socialAccountFor($organizationOwner, $editor, 'editor-owned');
        $this->pagesFor($organizationOwner, $account);

        // These are deliberately the legacy global permissions that used to
        // grant access. The editor still must be denied management: only the
        // active organization's role matrix is authoritative now.
        $legacyPermissions = [
            'social-accounts.update',
            'social-accounts.create',
            'social-accounts.oauth.authorize',
            'social-accounts.oauth.callback',
            'social-accounts.pages.manage',
        ];
        foreach ($legacyPermissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
        $editor->givePermissionTo($legacyPermissions);

        Sanctum::actingAs($editor);

        $this->inOrganization($organizationOwner)
            ->getJson('/api/v1/catalog/social-providers')
            ->assertOk();

        $this->inOrganization($organizationOwner)
            ->getJson('/api/v1/users/'.$editor->id.'/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $account->id);

        $this->inOrganization($organizationOwner)
            ->getJson('/api/v1/users/'.$editor->id.'/social-accounts/'.$account->id.'/pages')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->inOrganization($organizationOwner)
            ->putJson('/api/v1/users/'.$editor->id.'/social-accounts/'.$account->id, [
                'account_name' => 'Editor must not update this',
            ])
            ->assertForbidden();

        // Sprint C: the generic store() endpoint this block used to check
        // was removed entirely (see SocialAccountController) — editor being
        // forbidden from connecting is still fully proven by the OAuth
        // authorize/callback and Telegram connect checks below.
        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$editor->id.'/social-accounts/authorize', [
                'provider' => 'facebook',
                'redirect_uri' => 'smartpublisher://oauth/callback',
            ])
            ->assertForbidden();

        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$editor->id.'/social-accounts/callback', [
                'provider' => 'facebook',
                'code' => 'editor-code',
                'state' => 'editor-state',
            ])
            ->assertForbidden();

        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$editor->id.'/social-accounts/telegram/connect', [
                'bot_token' => '123:editor-cannot-connect',
            ])
            ->assertForbidden();

        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$editor->id.'/social-accounts/'.$account->id.'/pages/select', [
                'page_ids' => [],
            ])
            ->assertForbidden();

        $this->inOrganization($organizationOwner)
            ->postJson('/api/v1/users/'.$editor->id.'/social-accounts/'.$account->id.'/test')
            ->assertForbidden();
    }

    public function test_manager_cannot_access_social_accounts_outside_the_current_organization(): void
    {
        $firstOrganizationOwner = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToOrganization($firstOrganizationOwner, $manager, OrganizationRole::Manager);

        $secondOrganizationOwner = User::factory()->create();
        $foreignAccount = $this->socialAccountFor($secondOrganizationOwner, $secondOrganizationOwner, 'foreign');

        Sanctum::actingAs($manager);

        $this->inOrganization($firstOrganizationOwner)
            ->getJson('/api/v1/users/'.$secondOrganizationOwner->id.'/social-accounts')
            ->assertNotFound();

        $this->inOrganization($firstOrganizationOwner)
            ->getJson('/api/v1/users/'.$secondOrganizationOwner->id.'/social-accounts/'.$foreignAccount->id)
            ->assertNotFound();

        // Sprint C: the generic store() endpoint this block used to check
        // was removed entirely — same cross-organization 404 re-proven here
        // against the real remaining creation path instead.
        $this->inOrganization($firstOrganizationOwner)
            ->postJson('/api/v1/users/'.$secondOrganizationOwner->id.'/social-accounts/telegram/connect', [
                'bot_token' => 'blocked-cross-organization-bot',
            ])
            ->assertNotFound();
    }

    public function test_social_provider_catalog_only_advertises_closed_beta_providers_in_production(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $originalEnvironment = app()->environment();
        app()->instance('env', 'production');

        try {
            $this->getJson('/api/v1/catalog/social-providers')
                ->assertOk()
                ->assertJsonPath('data.providers', ['facebook', 'telegram']);
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }

    private function addToOrganization(User $organizationOwner, User $member, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organizationOwner->current_organization_id,
                'user_id' => $member->id,
            ],
            ['role' => $role, 'status' => 'active'],
        );
    }

    private function socialAccountFor(User $organizationOwner, User $accountOwner, string $suffix): SocialAccount
    {
        return $this->asOrganizationOf($organizationOwner, fn (): SocialAccount => SocialAccount::query()->create([
            'user_id' => $accountOwner->id,
            'provider' => 'facebook',
            'provider_account_id' => 'social-account-'.$suffix,
            'account_name' => 'Social account '.$suffix,
            'status' => 'connected',
            'is_active' => true,
        ]));
    }

    /**
     * @return array{0: SocialPage, 1: SocialPage}
     */
    private function pagesFor(User $organizationOwner, SocialAccount $account): array
    {
        return $this->asOrganizationOf($organizationOwner, function () use ($account): array {
            $firstPage = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'page-'.$account->id.'-first',
                'kind' => 'page',
                'name' => 'First page',
                'can_publish' => true,
                'status' => 'valid',
            ]);
            $secondPage = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'page-'.$account->id.'-second',
                'kind' => 'page',
                'name' => 'Second page',
                'can_publish' => true,
                'is_selected' => true,
                'status' => 'valid',
            ]);

            return [$firstPage, $secondPage];
        });
    }

    /**
     * @return $this
     */
    private function inOrganization(User $organizationOwner): self
    {
        return $this->withHeaders([
            'X-Organization-Id' => (string) $organizationOwner->current_organization_id,
        ]);
    }
}
