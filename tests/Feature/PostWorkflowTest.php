<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_and_schedule_post(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.create', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'posts.schedule', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'posts.view', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'posts.update', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['posts.create', 'posts.schedule', 'posts.view', 'posts.update']);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/posts', [
            'title' => 'Queued Post',
            'content' => 'Hello world',
        ]);

        $createResponse->assertCreated();

        $postId = (int) $createResponse->json('data.id');

        $scheduleResponse = $this->postJson('/api/v1/posts/'.$postId.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ]);

        $scheduleResponse->assertOk();

        $this->assertDatabaseHas('posts', [
            'id' => $postId,
            'status' => 'scheduled',
        ]);
    }

    /**
     * Regression test for the create-post idempotency fix: a client whose
     * response was lost after the server already committed (its own retry,
     * or an offline-outbox entry replayed later) resends the exact same
     * Idempotency-Key — the fix must recognize that and return the
     * original post rather than silently creating a second draft.
     */
    public function test_creating_a_post_twice_with_the_same_idempotency_key_returns_the_same_post(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.create', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['posts.create']);

        Sanctum::actingAs($user);

        $payload = ['title' => 'Retried Post', 'content' => 'Hello world'];
        $headers = ['Idempotency-Key' => 'client-generated-key-123'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/posts', $payload);
        $first->assertCreated();
        $postId = (int) $first->json('data.id');

        $second = $this->withHeaders($headers)->postJson('/api/v1/posts', $payload);
        $second->assertOk();
        $this->assertSame($postId, (int) $second->json('data.id'));

        $this->assertSame(1, Post::query()->where('idempotency_key', 'client-generated-key-123')->count());
    }

    public function test_creating_a_post_without_an_idempotency_key_still_works_as_before(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.create', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['posts.create']);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts', ['title' => 'No Key A'])->assertCreated();
        $this->postJson('/api/v1/posts', ['title' => 'No Key B'])->assertCreated();

        $this->assertSame(2, Post::query()->whereNull('idempotency_key')->count());
    }

    public function test_post_owner_can_view_post_even_without_broad_permission(): void
    {
        $user = User::factory()->create();
        $post = $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Owned Post',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/posts/'.$post->id)
            ->assertOk()
            ->assertJsonPath('data.id', $post->id);
    }

    public function test_fetching_a_post_returns_its_target_page_ids(): void
    {
        $user = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'posts.view', 'guard_name' => 'sanctum']);
        Permission::query()->firstOrCreate(['name' => 'posts.update', 'guard_name' => 'sanctum']);
        $user->givePermissionTo(['posts.view', 'posts.update']);

        [$post, $page] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Targeted Post',
                'status' => 'draft',
            ]);

            $socialAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'fb-123',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $socialAccount->id,
                'page_id' => 'page-1',
                'name' => 'Test Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $post->socialPages()->sync([$page->id]);

            return [$post, $page];
        });

        Sanctum::actingAs($user);

        // This is the exact PostResource output Flutter's edit screen builds
        // its page-selection checkboxes from — if target_page_ids is ever
        // missing again, this regresses to an empty selection.
        $this->getJson('/api/v1/posts/'.$post->id)
            ->assertOk()
            ->assertJsonPath('data.target_page_ids', [$page->id]);

        // Saving an edit with exactly the fetched target_page_ids (what the
        // real Flutter edit flow does) must not detach the page.
        $this->putJson('/api/v1/posts/'.$post->id, [
            'title' => 'Targeted Post (edited)',
            'content' => 'Updated body',
            'target_page_ids' => [$page->id],
        ])->assertOk();

        $this->assertDatabaseHas('post_targets', [
            'post_id' => $post->id,
            'social_page_id' => $page->id,
        ]);
    }
}
