<?php

namespace Tests\Feature;

use App\Contracts\AI\AiProviderInterface;
use App\Enums\AiOperation;
use App\Enums\AiTone;
use App\Models\AiUsageLog;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiComposerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_spell_check_returns_a_sanitized_proposal_and_never_logs_post_text(): void
    {
        $provider = new RecordingAiProvider('__echo_input__');
        $this->app->instance(AiProviderInterface::class, $provider);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/ai/spell-check', [
            'text' => 'راسل user@example.com في 2026-08-21 عبر https://example.test/a',
            'tone' => 'formal',
        ])->assertOk()
            ->assertJsonPath('data.proposed_text', 'راسل user@example.com في 2026-08-21 عبر https://example.test/a');

        $this->assertStringNotContainsString('user@example.com', $provider->receivedText);
        $this->assertStringContainsString('[SPP-', $provider->receivedText);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $user->current_organization_id,
            'user_id' => $user->id,
            'operation' => 'spell_check',
            'status' => 'succeeded',
        ]);
        $this->assertSame(1, AiUsageLog::query()->count());
    }

    public function test_ai_request_rejects_an_oversized_or_invalid_payload_before_provider_call(): void
    {
        $provider = new RecordingAiProvider('unused');
        $this->app->instance(AiProviderInterface::class, $provider);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/ai/rewrite', [
            'text' => '',
            'tone' => 'invalid-tone',
        ])->assertUnprocessable();

        $this->assertSame('', $provider->receivedText);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_pre_publish_check_reports_missing_targets_without_mutating_the_post(): void
    {
        $user = User::factory()->create();
        $post = $this->asOrganizationOf($user, fn (): Post => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'مسودة بلا أهداف',
            'content' => 'نص',
            'status' => 'draft',
        ]));
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/pre-publish-check')
            ->assertOk()
            ->assertJsonPath('data.errors.0.code', 'publish_target_required');

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'draft',
        ]);
    }

    public function test_pre_publish_check_warns_about_an_identical_post_in_the_same_organization(): void
    {
        $user = User::factory()->create();
        $post = $this->asOrganizationOf($user, function () use ($user): Post {
            Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Original',
                'content' => 'Same carefully reviewed content',
                'status' => 'draft',
            ]);

            return Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Candidate',
                'content' => 'Same carefully reviewed content',
                'status' => 'draft',
            ]);
        });
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/pre-publish-check')
            ->assertOk()
            ->assertJsonPath('data.warnings.0.code', 'possible_duplicate_content');
    }

    /**
     * Regression: PrePublishValidationService::assertNoBlockingErrors() used
     * to run only inside publishNow() — schedule() (and, before it, an
     * approval request) never ran it at all, despite this feature's own
     * documentation claiming otherwise. A scheduled post with an invalid
     * link now gets caught here too, before it is ever queued.
     */
    public function test_scheduling_a_post_with_an_invalid_link_is_rejected(): void
    {
        $user = $this->authorizedScheduler();

        $post = $this->postJson('/api/v1/posts', [
            'title' => 'Broken link post',
            'content' => 'Check this out: https:// not-a-real-link',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/posts/'.$post.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonPath('errors.pre_publish.0', 'The post contains an invalid link.');

        $this->assertDatabaseHas('posts', ['id' => $post, 'status' => 'draft']);
    }

    /**
     * Confirms the fix above did NOT also start requiring a page selection
     * at schedule time — that has been this codebase's own deliberate,
     * pre-existing behavior (ClosedBetaPublishingGate's own target-set
     * assertions are themselves no-ops for an empty page collection), only
     * newly wired in alongside the content-level checks.
     */
    public function test_scheduling_a_post_with_no_pages_selected_still_succeeds(): void
    {
        $user = $this->authorizedScheduler();

        $post = $this->postJson('/api/v1/posts', [
            'title' => 'No target yet',
            'content' => 'Perfectly fine content.',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/posts/'.$post.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertOk();
    }

    private function authorizedScheduler(): User
    {
        $user = User::factory()->create();
        foreach (['posts.create', 'posts.schedule', 'posts.view', 'posts.update'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
        $user->givePermissionTo(['posts.create', 'posts.schedule', 'posts.view', 'posts.update']);
        Sanctum::actingAs($user);

        return $user;
    }
}

final class RecordingAiProvider implements AiProviderInterface
{
    public string $receivedText = '';

    public function __construct(private readonly string $response) {}

    public function name(): string
    {
        return 'test-provider';
    }

    public function generate(
        AiOperation $operation,
        string $text,
        AiTone $tone,
        ?string $targetLanguage = null,
        array $platforms = [],
    ): string {
        $this->receivedText = $text;

        return $this->response === '__echo_input__' ? $text : $this->response;
    }
}
