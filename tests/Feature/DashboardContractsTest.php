<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_get_analytics_calendar_notifications_and_settings_contracts(): void
    {
        $user = User::factory()->create();

        Permission::query()->firstOrCreate(['name' => 'notifications.view', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'settings.view', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['notifications.view', 'settings.view']);

        $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Scheduled item',
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_posts',
                    'published',
                    'failed',
                    'scheduled',
                    'draft',
                    'engagement' => ['score', 'trend'],
                    'updated_at',
                ],
                'meta',
                'errors',
            ]);

        $this->getJson('/api/v1/calendar')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => ['id', 'post_id', 'title', 'status', 'scheduled_at', 'branch'],
                    ],
                ],
                'meta',
                'errors',
            ]);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'unread',
                    'items' => [
                        '*' => ['id', 'type', 'title', 'body', 'read', 'created_at'],
                    ],
                ],
                'meta',
                'errors',
            ]);

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'locale',
                    'timezone',
                    'date_format',
                    'time_format',
                    'features',
                ],
                'meta',
                'errors',
            ]);
    }
}
