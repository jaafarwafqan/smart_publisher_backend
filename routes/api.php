<?php

use App\Http\Controllers\Api\V1\AccountDataDeletionController;
use App\Http\Controllers\Api\V1\AccountDataExportController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AdminOrganizationController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\MediaLibraryController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\PublishingController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use App\Http\Controllers\Api\V1\SystemSettingsController;
use App\Http\Controllers\Api\V1\TwoFactorAuthController;
use App\Http\Controllers\Api\V1\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');
    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');
        Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:password-reset-request');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset-consume');
        // Deliberately not behind auth:sanctum: the signed URL itself (id +
        // hash + expiry + signature, checked by the 'signed' middleware) is
        // what proves the click came from the real mailbox — the same
        // trust boundary Laravel's own default web flow relies on. Must
        // stay named 'verification.verify': URL::temporarySignedRoute()
        // inside ApiVerifyEmailNotification resolves this route by name.
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')
            ->name('verification.verify');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware(['auth:sanctum', 'throttle:email-verification-resend']);
        // Completes a login that AuthController::login() paused on because
        // the account has 2FA enabled — deliberately not behind
        // auth:sanctum, see TwoFactorChallengeController's docblock.
        Route::post('/two-factor/challenge', [TwoFactorChallengeController::class, 'challenge'])
            ->middleware('throttle:two-factor-challenge');
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/two-factor/enable', [TwoFactorAuthController::class, 'enable']);
            Route::post('/two-factor/confirm', [TwoFactorAuthController::class, 'confirm']);
            Route::post('/two-factor/disable', [TwoFactorAuthController::class, 'disable']);
        });
    });
    // An account owner must retain a privacy/deletion path even after losing
    // every organisation membership, so this self-service route is
    // authenticated but intentionally not tenant-gated.
    Route::middleware('auth:sanctum')->post('/account/data-deletion-requests', [AccountDataDeletionController::class, 'store']);
    // Same reasoning: the "download my data" counterpart to the deletion
    // request above, also intentionally not tenant-gated.
    Route::middleware('auth:sanctum')->get('/account/data-export', [AccountDataExportController::class, 'export']);
    // Platform administration never enters the tenant middleware group. Its
    // authorization is an independent capability and its few cross-org reads
    // are explicitly scoped inside the dedicated controllers.
    Route::middleware(['auth:sanctum', 'super_admin'])->prefix('admin')->group(function (): void {
        Route::get('/dashboard', [AdminDashboardController::class, 'show']);

        Route::get('/organizations', [AdminOrganizationController::class, 'index']);
        Route::post('/organizations', [AdminOrganizationController::class, 'store'])->middleware('throttle:platform-admin-write');
        Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show']);
        Route::put('/organizations/{organization}', [AdminOrganizationController::class, 'update'])->middleware('throttle:platform-admin-write');
        Route::post('/organizations/{organization}/status', [AdminOrganizationController::class, 'updateStatus'])->middleware('throttle:platform-admin-write');

        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store'])->middleware('throttle:platform-admin-write');
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->middleware('throttle:platform-admin-write');
        Route::put('/users/{user}/memberships', [AdminUserController::class, 'syncMemberships'])->middleware('throttle:platform-admin-write');
        Route::post('/users/{user}/platform-role', [AdminUserController::class, 'updatePlatformRole'])->middleware('throttle:platform-admin-write');
        Route::post('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->middleware('throttle:platform-admin-write');
    });
    // Sprint 2 (API Hardening): the /accounts/* group (AccountController)
    // was removed here — only index() was ever implemented; connect/show/
    // update/destroy had no controller methods at all and 500'd on every
    // call (confirmed via the README's own documented gap and a repo-wide
    // grep finding zero test coverage and zero remaining Flutter callers —
    // AccountRepositoryImpl was migrated onto SocialAccountController's
    // real, complete /users/{user}/social-accounts/* routes below in an
    // earlier session). Kept as dead, broken, undocumented-elsewhere API
    // surface was strictly worse than removing it.
    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::prefix('auth')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });

        Route::get('/branches', [BranchController::class, 'index'])->middleware('permission:branches.view');
        Route::post('/branches', [BranchController::class, 'store'])->middleware('permission:branches.create');
        Route::get('/branches/{branch}', [BranchController::class, 'show'])->middleware('permission:branches.view');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:branches.delete');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');

        Route::post('/users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('permission:roles.assign');
        Route::get('/users/{user}/permissions', [UserController::class, 'permissions'])->middleware('permission:permissions.view');
        Route::post('/users/{user}/permissions', [UserController::class, 'assignDirectPermissions'])->middleware('permission:roles.assign');

        Route::get('/users/{user}/tokens', [UserController::class, 'tokens'])->middleware('permission:tokens.view');
        Route::post('/users/{user}/tokens', [UserController::class, 'createToken'])->middleware('permission:tokens.revoke');
        Route::delete('/users/{user}/tokens/{tokenId}', [UserController::class, 'revokeToken'])->middleware('permission:tokens.revoke');

        Route::get('/catalog/roles-permissions', [UserController::class, 'rolesAndPermissionsCatalog'])->middleware('permission:roles.view');
        Route::get('/catalog/social-providers', [SocialAccountController::class, 'providers']);

        Route::get('/users/{user}/social-accounts', [SocialAccountController::class, 'index']);
        Route::post('/users/{user}/social-accounts/authorize', [SocialAccountController::class, 'beginOAuthAuthorization']);
        Route::post('/users/{user}/social-accounts/callback', [SocialAccountController::class, 'callback']);
        Route::post('/users/{user}/social-accounts/telegram/connect', [SocialAccountController::class, 'connectTelegramBot']);
        Route::post('/users/{user}/social-accounts', [SocialAccountController::class, 'store']);
        Route::get('/users/{user}/social-accounts/{socialAccount}', [SocialAccountController::class, 'show']);
        Route::put('/users/{user}/social-accounts/{socialAccount}', [SocialAccountController::class, 'update']);
        Route::delete('/users/{user}/social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy']);
        Route::post('/users/{user}/social-accounts/{socialAccount}/refresh-token', [SocialAccountController::class, 'refreshToken']);
        Route::post('/users/{user}/social-accounts/{socialAccount}/test', [SocialAccountController::class, 'testConnection']);
        Route::post('/users/{user}/social-accounts/{socialAccount}/status', [SocialAccountController::class, 'setStatus']);
        Route::get('/users/{user}/social-accounts/{socialAccount}/pages', [SocialAccountController::class, 'listPages']);
        Route::post('/users/{user}/social-accounts/{socialAccount}/pages/sync', [SocialAccountController::class, 'syncPages']);
        Route::post('/users/{user}/social-accounts/{socialAccount}/pages/add', [SocialAccountController::class, 'addPage']);
        Route::delete('/users/{user}/social-accounts/{socialAccount}/pages/{socialPage}', [SocialAccountController::class, 'destroyPage']);
        Route::post('/users/{user}/social-accounts/{socialAccount}/pages/select', [SocialAccountController::class, 'selectPages']);

        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch']);

        Route::get('/organization/members', [OrganizationMembershipController::class, 'index']);
        Route::post('/organization/members', [OrganizationMembershipController::class, 'store']);
        Route::put('/organization/members/{user}', [OrganizationMembershipController::class, 'update']);
        Route::delete('/organization/members/{user}', [OrganizationMembershipController::class, 'destroy']);

        // Post access is enforced by the current organization membership
        // (PostController + PostPolicy), never by a legacy app-wide role.
        Route::get('/posts', [PostController::class, 'index']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::get('/posts/{post}', [PostController::class, 'show']);
        Route::put('/posts/{post}', [PostController::class, 'update']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        Route::post('/posts/{post}/schedule', [PostController::class, 'schedule'])->middleware('throttle:publish');
        Route::post('/posts/{post}/publish-now', [PostController::class, 'publishNow'])->middleware('throttle:publish');
        Route::post('/posts/{post}/draft', [PostController::class, 'markDraft']);
        Route::post('/posts/{post}/cancel', [PostController::class, 'cancel']);
        Route::post('/posts/{post}/approve', [PostController::class, 'approve']);
        Route::post('/posts/{post}/reject', [PostController::class, 'reject']);

        Route::get('/media', [MediaLibraryController::class, 'index']);
        Route::post('/media', [MediaLibraryController::class, 'store']);
        Route::post('/media/{mediaAttachment}/compress', [MediaLibraryController::class, 'compress']);
        Route::post('/posts/{post}/media/attach', [MediaLibraryController::class, 'attachToPost']);
        Route::delete('/posts/{post}/media/{mediaAttachment}', [MediaLibraryController::class, 'detachFromPost']);
        Route::delete('/media/{mediaAttachment}', [MediaLibraryController::class, 'destroy']);

        // Analytics and calendar access are enforced by the active
        // OrganizationMembership capability in their controllers.
        Route::get('/analytics', [AnalyticsController::class, 'index']);
        Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('/analytics/posts', [AnalyticsController::class, 'bulk']);
        Route::get('/analytics/posts/{post}', [AnalyticsController::class, 'show']);
        // Notifications are recipient-private and tenant-scoped in the
        // controller/policy. An active organization membership is the only
        // capability needed to read one's own operational notices.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{notification}', [NotificationController::class, 'markAsRead']);
        Route::get('/calendar', [CalendarController::class, 'index']);
        // Organization settings are governed by the active membership's
        // SettingsManage capability, enforced in SettingsController.
        Route::get('/settings', [SettingsController::class, 'index']);

        Route::get('/system-settings/oauth-providers', [SystemSettingsController::class, 'index'])->middleware('permission:system-settings.view');
        Route::put('/system-settings/oauth-providers/{provider}', [SystemSettingsController::class, 'update'])->middleware('permission:system-settings.manage');
        Route::post('/system-settings/oauth-providers/{provider}/test', [SystemSettingsController::class, 'testConnection'])->middleware('permission:system-settings.manage');
        Route::get('/system-settings/oauth-providers/{provider}/audit-log', [SystemSettingsController::class, 'auditLog'])->middleware('permission:system-settings.view');

        Route::post('/publishing/tick', [PublishingController::class, 'runSchedulerTick'])->middleware('permission:publishing.manage');
        Route::get('/publishing/dead-letters', [PublishingController::class, 'deadLetters'])->middleware('permission:publishing.monitor');
        Route::post('/publishing/dead-letters/{deadLetterJob}/retry', [PublishingController::class, 'retryDeadLetter'])->middleware('permission:publishing.manage');
        Route::post('/publishing/circuit-breaker/clear', [PublishingController::class, 'clearProviderCircuit'])->middleware('permission:publishing.manage');
    });
});
