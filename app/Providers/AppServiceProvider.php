<?php

namespace App\Providers;

use App\Models\MediaAttachment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Policies\MediaAttachmentPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PostPolicy;
use App\Policies\SocialAccountPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton per container instance — the framework creates a fresh
        // container per HTTP request and per queued job, so this never
        // leaks context between them on its own. See TenantContext's
        // docblock for the full reasoning.
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertSafeDeploymentConfiguration();

        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(MediaAttachment::class, MediaAttachmentPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(SocialAccount::class, SocialAccountPolicy::class);

        // CTO audit 4.4: IP-only throttling let an attacker spread a
        // brute-force attack against ONE victim account across many IPs,
        // each getting its own fresh 10/minute budget. Stacking a second,
        // stricter limit keyed on the submitted identifier (not the IP)
        // closes that gap without changing the response for a normal user
        // — a wrong password still just looks like a normal 401/422, no
        // extra detail is revealed either way, so this doesn't create a
        // user-enumeration signal that wasn't already there.
        RateLimiter::for('auth-login', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            // AuthController::login() accepts EITHER field as the identifier
            // (email OR username) — must match that exact fallback here, or
            // a username-based attempt would only ever be IP-limited.
            $identifier = mb_strtolower(trim((string) ($request->input('email') ?: $request->input('username', ''))));

            return [
                Limit::perMinute(10)->by('login-ip:'.$ip),
                Limit::perMinute(5)->by('login-id:'.$identifier),
            ];
        });

        RateLimiter::for('auth-refresh', function (Request $request): Limit {
            $tokenPart = substr((string) $request->input('refresh_token', ''), 0, 24);
            $key = sprintf('refresh:%s:%s', (string) ($request->ip() ?? 'unknown'), $tokenPart);

            return Limit::perMinute(30)->by($key);
        });

        // Sprint 2 (API Hardening): before this, only auth-login/auth-refresh
        // had any rate limit at all — every other endpoint (posts, media
        // upload, publish-now, analytics, admin settings) was completely
        // unthrottled. Applied via Middleware::throttleApi('api') in
        // bootstrap/app.php, covering every route in routes/api.php.
        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->user()?->id ?: 'ip:'.(string) ($request->ip() ?? 'unknown');

            return Limit::perMinute(120)->by($key);
        });

        // Creating users or changing platform access has a much smaller
        // budget than ordinary API traffic. It limits a compromised platform
        // token without revealing anything about target accounts.
        RateLimiter::for('platform-admin-write', function (Request $request): Limit {
            $key = 'platform-admin-write:'.($request->user()?->id ?: 'ip:'.(string) ($request->ip() ?? 'unknown'));

            return Limit::perMinute(30)->by($key);
        });

        // A stricter, separate limit for publish-now/schedule specifically:
        // both dispatch real jobs that call an external provider (Facebook/
        // Telegram) API — a much lower ceiling than the general 'api' limit
        // is warranted so a runaway client (or a compromised token) can't
        // burn through the app's own provider quota or trigger a real
        // platform-side rate limit/ban.
        RateLimiter::for('publish', function (Request $request): Limit {
            $key = $request->user()?->id ?: 'ip:'.(string) ($request->ip() ?? 'unknown');

            return Limit::perMinute(20)->by($key);
        });

        // Sprint 4 (Commercial SaaS): forgot-password is a public,
        // pre-auth endpoint that triggers an email send — the same
        // IP+identifier double-limit pattern as auth-login, so a burst
        // against one victim's inbox can't be spread across many IPs, and
        // a single IP can't mass-probe many emails either. The password
        // broker's own per-email 60s throttle (config/auth.php) is a
        // separate, tighter layer already covering the repeated-same-email
        // case — this exists for the cases that misses.
        RateLimiter::for('password-reset-request', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $email = mb_strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(10)->by('reset-req-ip:'.$ip),
                Limit::perHour(5)->by('reset-req-email:'.$email),
            ];
        });

        // Consuming a reset token is a guessing target (60-char random
        // token, but still worth capping brute-force attempts server-side
        // rather than relying on the token's entropy alone).
        RateLimiter::for('password-reset-consume', function (Request $request): Limit {
            $key = 'reset-consume-ip:'.(string) ($request->ip() ?? 'unknown');

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('email-verification-resend', function (Request $request): Limit {
            $key = 'verify-resend-user:'.($request->user()?->id ?: 'ip:'.(string) ($request->ip() ?? 'unknown'));

            return Limit::perMinute(3)->by($key);
        });

        // Sprint 4 (Commercial SaaS): public self-registration is a new,
        // unauthenticated write endpoint that creates real accounts (and,
        // via PersonalOrganizationProvisioner, a real organization) — an
        // IP-only cap is enough here since, unlike login, there is no
        // existing victim identity to protect against credential
        // stuffing, only mass fake-account creation to slow down.
        RateLimiter::for('auth-register', function (Request $request): Limit {
            $key = 'register-ip:'.(string) ($request->ip() ?? 'unknown');

            return Limit::perHour(10)->by($key);
        });

        // Same double-limit shape as auth-login: a 6-digit TOTP code (or a
        // recovery code) is a guessing target, so both the challenge_token
        // itself and the requesting IP get their own budget.
        RateLimiter::for('two-factor-challenge', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $tokenPart = substr((string) $request->input('challenge_token', ''), 0, 24);

            return [
                Limit::perMinute(20)->by('2fa-ip:'.$ip),
                Limit::perMinute(10)->by('2fa-token:'.$tokenPart),
            ];
        });
    }

    /**
     * Fail closed instead of exposing a partly-secure staging or production
     * environment. Local and testing keep their deliberately flexible setup.
     */
    private function assertSafeDeploymentConfiguration(): void
    {
        if (! $this->app->environment(['staging', 'production'])) {
            return;
        }

        $violations = [];
        $appUrl = (string) config('app.url');
        $appUrlIsHttps = filter_var($appUrl, FILTER_VALIDATE_URL)
            && parse_url($appUrl, PHP_URL_SCHEME) === 'https';

        if (config('app.debug')) {
            $violations[] = 'APP_DEBUG must be false';
        }

        if (! $appUrlIsHttps) {
            $violations[] = 'APP_URL must be an HTTPS URL';
        }

        if (! config('security.require_https')) {
            $violations[] = 'SECURITY_REQUIRE_HTTPS must be true';
        }

        if (! config('session.secure')) {
            $violations[] = 'SESSION_SECURE_COOKIE must be true';
        }

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['array', 'failover', 'log'], true)) {
            $violations[] = 'MAIL_MAILER must deliver mail through a real provider';
        }

        if (! filter_var((string) config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            $violations[] = 'MAIL_FROM_ADDRESS must be a valid email address';
        }

        $origins = config('cors.allowed_origins', []);
        $originsAreExplicitHttps = is_array($origins)
            && $origins !== []
            && array_all(
                $origins,
                fn (mixed $origin): bool => is_string($origin) && str_starts_with($origin, 'https://'),
            );

        if (! $originsAreExplicitHttps) {
            $violations[] = 'CORS_ALLOWED_ORIGINS must contain explicit HTTPS origins only';
        }

        if ($violations !== []) {
            throw new RuntimeException(
                'Refusing to start with unsafe '.$this->app->environment().' configuration: '.implode('; ', $violations).'.'
            );
        }
    }
}
