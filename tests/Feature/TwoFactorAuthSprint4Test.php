<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Sprint 4 (Commercial SaaS): TOTP-based MFA — enable/confirm/disable for
 * the authenticated user, plus the login-time challenge step that
 * AuthController::login() hands off to once 2FA is confirmed.
 */
class TwoFactorAuthSprint4Test extends TestCase
{
    use RefreshDatabase;

    private function currentOtp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function enableAndConfirmTwoFactor(User $user): array
    {
        Sanctum::actingAs($user);

        $enableResponse = $this->postJson('/api/v1/auth/two-factor/enable')->assertOk();
        $secret = $enableResponse->json('data.secret');

        $confirmResponse = $this->postJson('/api/v1/auth/two-factor/confirm', [
            'code' => $this->currentOtp($secret),
        ])->assertOk();

        return ['secret' => $secret, 'recovery_codes' => $confirmResponse->json('data.recovery_codes')];
    }

    public function test_enabling_two_factor_generates_an_unconfirmed_secret(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/two-factor/enable')->assertOk();

        $this->assertNotEmpty($response->json('data.secret'));
        $this->assertStringStartsWith('otpauth://totp/', $response->json('data.otpauth_url'));
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_enabling_twice_without_disabling_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->enableAndConfirmTwoFactor($user);

        $this->postJson('/api/v1/auth/two-factor/enable')->assertStatus(422);
    }

    public function test_confirming_with_a_valid_code_activates_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $result = $this->enableAndConfirmTwoFactor($user);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $result['recovery_codes']);
    }

    public function test_confirming_with_an_invalid_code_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/two-factor/enable')->assertOk();

        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('errors.message', 'Invalid authentication code.');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirming_without_enabling_first_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '123456'])->assertStatus(422);
    }

    public function test_disabling_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CorrectPassword123')]);
        $this->enableAndConfirmTwoFactor($user);

        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'WrongPassword'])
            ->assertStatus(422);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'CorrectPassword123'])
            ->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_login_with_two_factor_enabled_returns_a_challenge_instead_of_tokens(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CorrectPassword123')]);
        $this->enableAndConfirmTwoFactor($user);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk();

        $this->assertTrue($response->json('data.two_factor_required'));
        $this->assertNotEmpty($response->json('data.challenge_token'));
        $this->assertArrayNotHasKey('access_token', $response->json('data'));
    }

    public function test_the_two_factor_challenge_completes_login_with_a_valid_code(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CorrectPassword123')]);
        $result = $this->enableAndConfirmTwoFactor($user);

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk();

        $challengeToken = $login->json('data.challenge_token');

        $response = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentOtp($result['secret']),
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.access_token'));

        // The challenge_token is single-use.
        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentOtp($result['secret']),
        ])->assertStatus(401);
    }

    public function test_the_two_factor_challenge_completes_login_with_a_valid_recovery_code_and_consumes_it(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CorrectPassword123')]);
        $result = $this->enableAndConfirmTwoFactor($user);
        $recoveryCode = $result['recovery_codes'][0];

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $login->json('data.challenge_token'),
            'recovery_code' => $recoveryCode,
        ])->assertOk()->assertJsonPath('data.message', 'Login successful.');

        $this->assertNotContains($recoveryCode, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_the_two_factor_challenge_rejects_an_invalid_code(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CorrectPassword123')]);
        $this->enableAndConfirmTwoFactor($user);

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $login->json('data.challenge_token'),
            'code' => '000000',
        ])->assertStatus(401);
    }

    public function test_the_two_factor_challenge_rejects_an_unknown_challenge_token(): void
    {
        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => 'not-a-real-token',
            'code' => '123456',
        ])->assertStatus(401);
    }

    public function test_login_without_two_factor_enabled_is_unaffected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('CorrectPassword123')]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertOk()->assertJsonPath('data.message', 'Login successful.');
    }
}
