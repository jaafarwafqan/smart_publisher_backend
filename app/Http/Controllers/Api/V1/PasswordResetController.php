<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sprint 4 (Commercial SaaS): forgot/reset-password, built on Laravel's
 * standard password broker (App\Models\User already gets CanResetPassword
 * for free from its base Authenticatable class — see
 * User::sendPasswordResetNotification()) and the existing
 * `password_reset_tokens` table. Delivered on MAIL_MAILER=log for now, per
 * the user's explicit Sprint 4 decision — no real mail provider wired yet.
 */
class PasswordResetController extends Controller
{
    /**
     * Deliberately returns the same generic message whether or not the
     * email belongs to a real account — the broker's INVALID_USER and
     * RESET_LINK_SENT statuses are folded into one response so this
     * endpoint cannot be used to enumerate registered emails.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Password::sendResetLink() calls User::sendPasswordResetNotification()
        // synchronously (ApiPasswordResetNotification isn't ShouldQueue) — a
        // mail-provider outage/misconfiguration must not surface as a 500
        // here, both because the enumeration-safe generic response below
        // must still be returned, and because a broken mail provider is an
        // ops problem, not something the requester caused or can fix.
        try {
            $status = Password::sendResetLink(['email' => $validated['email']]);
        } catch (Throwable $e) {
            Log::warning('Password reset email failed to send.', [
                'email' => $validated['email'],
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'If an account exists for that email address, a password reset link has been sent.',
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Too many password reset requests. Please try again later.',
            ], 429);
        }

        return response()->json([
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])
                    ->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));

                // A successful reset should invalidate any session
                // established before the account holder proved they still
                // control the mailbox tied to it — the same assumption a
                // "someone else may have had access" recovery flow always
                // makes elsewhere.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset token is invalid or has expired.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }
}
