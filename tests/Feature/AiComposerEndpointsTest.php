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
