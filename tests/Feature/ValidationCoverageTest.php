<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ValidationCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_validation_missing_and_invalid_fields(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => '123',
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_users_endpoint_validates_duplicate_email_and_not_found_show(): void
    {
        $admin = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'users.create', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'users.view', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo(['users.create', 'users.view']);

        User::query()->create([
            'name' => 'Existing',
            'email' => 'existing@test.local',
            'password' => bcrypt('Password@123'),
            'role' => 'user',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users', [
            'name' => 'Another',
            'email' => 'existing@test.local',
            'password' => 'Password@123',
        ])->assertStatus(422);

        $this->getJson('/api/v1/users/999999')->assertStatus(404);
    }

    public function test_posts_endpoint_validates_missing_required_and_not_found_on_attach(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.create', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'media.upload', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['posts.create', 'media.upload']);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts', [
            'content' => 'missing title',
        ])->assertStatus(422);

        $post = Post::query()->create([
            'user_id' => $user->id,
            'title' => 'My post',
            'status' => 'draft',
        ]);

        $this->postJson('/api/v1/posts/'.$post->id.'/media/attach', [
            'attachment_ids' => [999999],
        ])->assertStatus(422);
    }

    public function test_branches_validation_duplicate_code(): void
    {
        $admin = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'branches.create', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo('branches.create');

        Branch::query()->create([
            'name' => 'HQ',
            'code' => 'HQ',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/branches', [
            'name' => 'Dup',
            'code' => 'HQ',
            'is_active' => true,
        ])->assertStatus(422);
    }
}
