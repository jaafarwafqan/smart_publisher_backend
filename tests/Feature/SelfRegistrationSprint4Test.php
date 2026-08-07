<?php

namespace Tests\Feature;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\ApiVerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Sprint 4 (Commercial SaaS): public self-registration
 * (POST /api/v1/auth/register), per the user's explicit Sprint 4 decision
 * to enable full public self-registration rather than invite-only.
 */
class SelfRegistrationSprint4Test extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_can_self_register_and_is_auto_logged_in(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Publisher',
            'email' => 'new-publisher@example.com',
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'BrandNewPassword456',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Registration successful.')
            ->assertJsonPath('data.user.email', 'new-publisher@example.com')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in', 'token_type']]);

        $user = User::query()->where('email', 'new-publisher@example.com')->firstOrFail();

        // Access token from the response must actually work.
        $this->withHeader('Authorization', 'Bearer '.$response->json('data.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'new-publisher@example.com');

        $this->assertNotNull($user->current_organization_id, 'self-registration must auto-provision a personal organization');
        $this->assertTrue($user->isMemberOf($user->current_organization_id));
    }

    public function test_self_registration_sends_an_email_verification_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Verify Me',
            'email' => 'verify-me@example.com',
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'BrandNewPassword456',
        ])->assertCreated();

        $user = User::query()->where('email', 'verify-me@example.com')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, ApiVerifyEmailNotification::class);
    }

    public function test_self_registration_auto_assigns_the_free_plan_when_it_exists(): void
    {
        $freePlan = Plan::query()->create([
            'name' => 'Free',
            'slug' => 'free',
            'limits' => ['max_social_accounts' => 3],
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Free Plan User',
            'email' => 'free-plan-user@example.com',
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'BrandNewPassword456',
        ])->assertCreated();

        $user = User::query()->where('email', 'free-plan-user@example.com')->firstOrFail();

        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $user->current_organization_id)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame($freePlan->id, $subscription->plan_id);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $existing = User::factory()->create(['email' => 'already-taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone Else',
            'email' => 'already-taken@example.com',
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'BrandNewPassword456',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(1, User::query()->where('email', 'already-taken@example.com')->count());
    }

    public function test_registration_requires_password_confirmation_to_match(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'SomethingElseEntirely',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, User::query()->where('email', 'mismatch@example.com')->count());
    }

    public function test_registration_requires_a_minimum_password_length(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Too Short',
            'email' => 'too-short@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_the_register_endpoint_is_rate_limited(): void
    {
        config()->set('cache.default', 'array');

        $lastStatus = 200;
        for ($i = 0; $i < 11; $i++) {
            $lastStatus = $this->postJson('/api/v1/auth/register', [
                'name' => 'Flood Test',
                'email' => 'flood-'.$i.'@example.com',
                'password' => 'BrandNewPassword456',
                'password_confirmation' => 'BrandNewPassword456',
            ])->getStatusCode();
        }

        $this->assertSame(429, $lastStatus);
    }
}
