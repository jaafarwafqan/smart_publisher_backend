<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ApiPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Sprint 4 (Commercial SaaS): the forgot/reset-password flow — built on
 * Laravel's standard password broker and delivered on MAIL_MAILER=log per
 * the user's explicit decision this sprint (no real mail provider yet).
 */
class PasswordResetSprint4Test extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_reset_notification_for_a_real_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset-target@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset-target@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email address, a password reset link has been sent.');

        Notification::assertSentTo($user, ApiPasswordResetNotification::class);
    }

    /**
     * The exact same generic response for an unknown email — this endpoint
     * must not leak whether an address is registered.
     */
    public function test_forgot_password_gives_the_identical_generic_response_for_an_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody-here@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email address, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_reset_password_with_a_valid_token_actually_changes_the_password_and_revokes_existing_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);
        $token = Password::createToken($user);

        // A live token to prove the "reset revokes existing sessions"
        // behaviour actually fires.
        $preExistingToken = $user->createToken('access-token:pre-reset');
        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'BrandNewPassword456',
        ])->assertOk()->assertJsonPath('message', 'Password has been reset successfully.');

        $this->assertTrue(Hash::check('BrandNewPassword456', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count(), 'a successful reset must revoke every pre-existing token');
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'not-the-real-token',
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'BrandNewPassword456',
        ])->assertStatus(422)->assertJsonPath('message', 'This password reset token is invalid or has expired.');

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }

    public function test_reset_password_requires_password_confirmation_to_match(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'SomethingElseEntirely',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_the_reset_password_endpoint_is_rate_limited(): void
    {
        config()->set('cache.default', 'array');
        $user = User::factory()->create();

        $lastStatus = 200;
        for ($i = 0; $i < 11; $i++) {
            $lastStatus = $this->postJson('/api/v1/auth/reset-password', [
                'email' => $user->email,
                'token' => 'guess-'.$i,
                'password' => 'BrandNewPassword456',
                'password_confirmation' => 'BrandNewPassword456',
            ])->getStatusCode();
        }

        $this->assertSame(429, $lastStatus);
    }
}
