<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ApiVerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 4 (Commercial SaaS): email verification — MustVerifyEmail is now
 * implemented on User, delivered via a custom API-friendly notification
 * (log-mailer, per the user's explicit Sprint 4 decision) instead of
 * Laravel's default web-view-oriented VerifyEmail notification.
 */
class EmailVerificationSprint4Test extends TestCase
{
    use RefreshDatabase;

    private function signedVerifyUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_a_freshly_registered_user_can_verify_their_email_with_a_valid_signed_link(): void
    {
        $user = User::factory()->unverified()->create();
        $this->assertFalse($user->hasVerifiedEmail());

        $this->getJson($this->signedVerifyUrl($user))
            ->assertOk()
            ->assertJsonPath('message', 'Email address verified successfully.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verifying_with_a_tampered_hash_is_rejected_and_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('not-'.$user->email),
        ]);

        $this->getJson($url)->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verifying_with_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $expiredUrl = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        // Laravel's 'signed' middleware itself rejects an expired signature
        // before the controller ever runs.
        $this->getJson($expiredUrl)->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verifying_an_already_verified_email_is_a_harmless_no_op(): void
    {
        $user = User::factory()->create(); // factory default is already verified

        $this->getJson($this->signedVerifyUrl($user))
            ->assertOk()
            ->assertJsonPath('message', 'Email address already verified.');
    }

    public function test_an_authenticated_unverified_user_can_request_a_resend(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Verification link sent.');

        Notification::assertSentTo($user, ApiVerifyEmailNotification::class);
    }

    public function test_an_already_verified_user_resend_request_is_a_no_op(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Email address already verified.');

        Notification::assertNothingSent();
    }

    public function test_the_resend_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(401);
    }

    public function test_the_resend_endpoint_is_rate_limited(): void
    {
        config()->set('cache.default', 'array');
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $lastStatus = 200;
        for ($i = 0; $i < 4; $i++) {
            $lastStatus = $this->postJson('/api/v1/auth/email/verification-notification')->getStatusCode();
        }

        $this->assertSame(429, $lastStatus);
    }
}
