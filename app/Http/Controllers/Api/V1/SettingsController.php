<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsResource;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request, DashboardCacheService $cache): JsonResponse
    {
        $user = $request->user();
        $organizationId = app(TenantContext::class)->get();

        if (! $user instanceof User || ! $user->hasOrganizationPermission($organizationId, OrganizationPermission::SettingsManage)) {
            abort(403, 'You do not have permission to manage settings in this organization.');
        }

        $userId = (int) $user->id;

        $payload = $cache->rememberSettings($userId, function (): array {
            return [
                'locale' => (string) config('app.locale', 'en'),
                'timezone' => (string) config('app.timezone', 'UTC'),
                'date_format' => 'Y-m-d',
                'time_format' => 'H:i',
                'features' => [
                    'analytics' => true,
                    'notifications' => true,
                    'calendar' => true,
                    'social_accounts' => true,
                ],
            ];
        });

        $resource = new SettingsResource($payload);

        return response()->json($resource->resolve());
    }
}
