<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Sprint 4 (Commercial SaaS): TOTP-based MFA setup — enable/confirm/disable
 * — for the currently authenticated user. The login-time challenge step
 * (AuthController::login() short-circuiting into a two_factor_required
 * response, and the endpoint that completes it) lives in
 * TwoFactorChallengeController since that one is deliberately NOT behind
 * auth:sanctum — the user isn't fully authenticated yet at that point.
 */
class TwoFactorAuthController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

    /**
     * Generates and stores a new secret but leaves it unconfirmed — a
     * client that abandons setup mid-flow (never calls confirm()) must
     * never end up with two_factor_confirmed_at set on a secret it never
     * actually saved into an authenticator app.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled. Disable it before generating a new secret.',
            ], 422);
        }

        $secret = $this->twoFactor->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Scan this secret with your authenticator app, then confirm with a generated code.',
            'secret' => $secret,
            'otpauth_url' => $this->twoFactor->otpAuthUrl(config('app.name', 'Smart Publisher'), $user->email, $secret),
        ]);
    }

    /**
     * Proves the client actually captured the secret from enable() by
     * requiring one valid code from it — only then is 2FA considered
     * active, and only then are recovery codes issued.
     */
    public function confirm(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => 'Call the enable endpoint first to generate a secret.'], 422);
        }

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 422);
        }

        $validated = $request->validate(['code' => ['required', 'string']]);

        if (! $this->twoFactor->verify($user->two_factor_secret, $validated['code'])) {
            return response()->json(['message' => 'Invalid authentication code.'], 422);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            // Shown once, in plaintext, exactly like every other MFA
            // recovery-code flow — this is the only time they are
            // recoverable in cleartext; storage from here on is encrypted.
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Requires the current password, not just an authenticated session —
     * a stolen/leaked access token alone must not be enough to turn off
     * the second factor protecting the account it belongs to.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $validated = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'The provided password is incorrect.'], 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }
}
