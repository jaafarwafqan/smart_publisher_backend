<?php

namespace Tests\Feature;

use App\Models\OrganizationMembership;
use App\Models\SocialAccount;
use App\Models\User;
use App\Policies\SocialAccountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Role/permission remediation (2026-08-09), Sprint B acceptance criterion:
 * every Role × Action combination for the newly split social_accounts.x and
 * social_pages.x permissions is covered by a matrix test — the same
 * discipline PostRolePermissionMatrixTest already applies to posts.
 * Exercises SocialAccountPolicy directly against every (role, action) pair
 * derived from OrganizationRole::permissions() so a future change to the
 * matrix that widens or narrows a grant fails loudly here.
 */
class SocialAccountRolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function matrixProvider(): array
    {
        $actions = [
            'view', 'update', 'delete', 'refreshToken', 'testConnection',
            'changeStatus', 'viewPages', 'syncPages', 'selectPages',
        ];

        $rows = [
            // Owner/admin/manager hold every social_accounts.*/social_pages.*
            // grant — see OrganizationRole::permissions().
            'owner' => array_fill_keys($actions, true),
            'admin' => array_fill_keys($actions, true),
            'manager' => array_fill_keys($actions, true),
            // Editor/viewer only ever hold the two *View permissions.
            'editor' => [
                'view' => true, 'update' => false, 'delete' => false,
                'refreshToken' => false, 'testConnection' => false, 'changeStatus' => false,
                'viewPages' => true, 'syncPages' => false, 'selectPages' => false,
            ],
            'viewer' => [
                'view' => true, 'update' => false, 'delete' => false,
                'refreshToken' => false, 'testConnection' => false, 'changeStatus' => false,
                'viewPages' => true, 'syncPages' => false, 'selectPages' => false,
            ],
        ];

        $cases = [];
        foreach ($rows as $role => $expectations) {
            foreach ($expectations as $action => $expected) {
                $cases["{$role}/{$action}"] = [$role, $action, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('matrixProvider')]
    public function test_role_action_matrix(string $role, string $action, bool $expected): void
    {
        $organizationOwner = User::factory()->create();
        $actor = User::factory()->create();

        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $organizationOwner->current_organization_id, 'user_id' => $actor->id],
            ['role' => $role, 'status' => 'active'],
        );

        $account = $this->asOrganizationOf($organizationOwner, fn (): SocialAccount => SocialAccount::query()->create([
            'user_id' => $organizationOwner->id,
            'provider' => 'facebook',
            'provider_account_id' => "matrix-{$role}-{$action}",
            'status' => 'connected',
            'is_active' => true,
        ]));

        // syncPages/selectPages/viewPages all take the same SocialAccount
        // argument (the policy resolves the org from the account, not a
        // separate SocialPage row) — no page fixture is actually required.
        $policy = new SocialAccountPolicy;
        $result = $policy->{$action}($actor, $account);

        $this->assertSame(
            $expected,
            $result,
            "Expected role='{$role}' to ".($expected ? '' : 'NOT ')."be allowed to '{$action}'.",
        );
    }
}
