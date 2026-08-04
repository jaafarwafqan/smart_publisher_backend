<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarResource;
use App\Models\Post;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request, DashboardCacheService $cache): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $userId = (int) $user->id;
        $canViewAll = $user->hasOrganizationPermission(
            app(TenantContext::class)->get(),
            OrganizationPermission::PostsViewAll,
        );

        $payload = $cache->rememberCalendar($userId, $canViewAll, function () use ($canViewAll, $userId): array {
            $query = Post::query()
                ->with(['branch:id,name,code'])
                // Only genuinely pending work belongs on a *publishing calendar* —
                // `scheduled_at` is also stamped on posts published immediately
                // (an internal queue-processing detail), so filtering by that
                // column alone would list already-published posts as "upcoming".
                ->where('status', 'scheduled');

            // The tenant scope supplies the organization boundary. Editors
            // additionally receive only their own scheduled work, while a
            // PostsViewAll membership sees the active organization's queue.
            if (! $canViewAll) {
                $query->where('user_id', $userId);
            }

            $events = $query
                ->latest('scheduled_at')
                ->limit(50)
                ->get()
                ->map(fn (Post $post): array => [
                    'id' => 'event-'.$post->id,
                    'post_id' => (int) $post->id,
                    'title' => (string) $post->title,
                    'status' => (string) $post->status,
                    'scheduled_at' => optional($post->scheduled_at)?->toIso8601String(),
                    'branch' => $post->branch ? [
                        'id' => (int) $post->branch->id,
                        'name' => (string) $post->branch->name,
                        'code' => (string) $post->branch->code,
                    ] : null,
                ])->values()->all();

            return ['items' => $events];
        });

        return response()->json([
            'items' => CalendarResource::collection(collect($payload['items'] ?? []))->resolve(),
        ]);
    }

    private function authorizedUser(Request $request): User
    {
        $user = $request->user();
        $organizationId = app(TenantContext::class)->get();

        if (! $user instanceof User || ! (
            $user->hasOrganizationPermission($organizationId, OrganizationPermission::PostsViewOwn)
            || $user->hasOrganizationPermission($organizationId, OrganizationPermission::PostsViewAll)
        )) {
            abort(403, 'You do not have permission to view the calendar in this organization.');
        }

        return $user;
    }
}
