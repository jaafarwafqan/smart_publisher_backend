<?php

use App\Exceptions\Api\ApiException;
use App\Exceptions\Publishing\IllegalStateTransitionException;
use App\Http\Middleware\ApiEnvelopeMiddleware;
use App\Http\Middleware\EnforceHttpsMiddleware;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\RequestContextMiddleware;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SetLocaleFromHeaderMiddleware;
use App\Support\Tenancy\NoOrganizationMembershipException;
use App\Support\Tenancy\TenantContextNotSetException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The Docker deployment exposes only Caddy. It forwards the original
        // HTTPS scheme over the internal Caddy -> Nginx -> PHP-FPM network;
        // never set this to '*' when PHP itself is directly internet-facing.
        $trustedProxies = env('TRUSTED_PROXIES');
        if (is_string($trustedProxies) && $trustedProxies !== '') {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->append(EnforceHttpsMiddleware::class);
        $middleware->append(RequestContextMiddleware::class);
        // Must run before anything that can throw (ValidationException from
        // FormRequest resolution, AuthorizationException, ...) — global
        // middleware appended here wraps controller dispatch entirely, so
        // this ordering already guarantees app()->setLocale() takes effect
        // before the exception renderer below ever runs.
        $middleware->append(SetLocaleFromHeaderMiddleware::class);
        $middleware->append(HandleCors::class);
        $middleware->append(ApiEnvelopeMiddleware::class);
        $middleware->append(SecurityHeadersMiddleware::class);

        // Sprint 2 (API Hardening): the 'api' RateLimiter (AppServiceProvider)
        // now applies to every route in routes/api.php — previously nothing
        // in the api middleware group throttled requests at all.
        $middleware->throttleApi('api');

        // This is an API-only app with no "login" route to redirect to. Without this,
        // an unauthenticated request that doesn't send Accept: application/json makes
        // Laravel's default Authenticate middleware try to redirect to route('login'),
        // which doesn't exist, throwing RouteNotFoundException (500) instead of a 401.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'tenant' => ResolveTenantContext::class,
            'super_admin' => EnsureSuperAdmin::class,
        ]);

        // ResolveTenantContext must run before SubstituteBindings — otherwise
        // implicit route-model-binding (Post::query()->findOrFail($id) under
        // the hood, via {post} route parameters) queries a tenant-scoped
        // model before TenantContext is set, throwing instead of correctly
        // 404ing on a different organization's resource. Laravel's own
        // default priority list already runs auth middleware before
        // SubstituteBindings; 'tenant' (route middleware, not in that
        // default list at all) needs the same treatment explicitly.
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: ResolveTenantContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => __('api.validation_failed'),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => __('api.unauthenticated'),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => ['code' => ['unauthenticated']],
                ], 401);
            }

            if ($e instanceof IllegalStateTransitionException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => ['code' => ['invalid_state_transition']],
                ], 409);
            }

            // Sprint A (role/permission remediation): a real, user-facing
            // 403 for an account with no organization membership at all —
            // AuthController/RegisterController now avoid triggering this
            // during login/register/me, but any other call site that
            // resolves tenant context for a membership-less account should
            // still fail as a clean 403, not fall through to the generic
            // 500 branch below.
            if ($e instanceof NoOrganizationMembershipException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => ['code' => ['no_organization_membership']],
                ], 403);
            }

            if ($e instanceof TenantContextNotSetException) {
                return response()->json([
                    'success' => false,
                    'message' => __('api.internal_server_error'),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => ['code' => ['server_error']],
                ], 500);
            }

            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: __('api.unauthorized_action'),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => ['code' => ['unauthorized']],
                ], 403);
            }

            // Sprint 2 (API Hardening): implicit route-model binding
            // (Post::class $post route parameters etc.) throws
            // ModelNotFoundException, which Laravel's own exception
            // preparation converts to a NotFoundHttpException carrying the
            // ORIGINAL message verbatim — "No query results for model
            // [App\Models\Post] 999999" — straight through to every API
            // caller. That leaks the real internal Eloquent model class
            // path. Deliberately narrow: only a NotFoundHttpException whose
            // previous exception is a ModelNotFoundException is rewritten;
            // legitimate developer-authored `abort(404, '...')` calls
            // elsewhere (MediaLibraryController, SocialAccountController,
            // UserController) keep their own intentional message untouched.
            if ($e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => __('api.resource_not_found'),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => ['code' => ['resource_not_found']],
                ], 404);
            }

            if ($e instanceof ApiException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => $e->errors(),
                ], $e->status());
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $status >= 500 ? __('api.internal_server_error') : ($e->getMessage() ?: __('api.request_failed'));

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
                'meta' => (object) [],
                'errors' => [
                    'code' => [$status >= 500 ? 'server_error' : 'request_failed'],
                ],
            ], $status);
        });
    })->create();
