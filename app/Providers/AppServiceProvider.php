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
    }
}
