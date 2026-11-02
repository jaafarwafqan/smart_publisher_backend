<?php

namespace Tests\Feature;

use App\Enums\AiOperation;
use App\Enums\AiTone;
use App\Exceptions\AiProviderException;
use App\Infrastructure\ExternalServices\AI\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "What happens when the key is missing" and "the request itself bounds the
 * provider's response length" are both real production questions for this
 * gateway — not just AIWritingService's post-hoc truncation.
 */
class OpenAiCompatibleProviderTest extends TestCase
{
    public function test_a_missing_provider_configuration_fails_closed_with_a_clear_error(): void
    {
        config(['ai.providers.openai_compatible.api_key' => '']);
        config(['ai.providers.openai_compatible.endpoint' => 'https://ai.test/v1/chat/completions']);
        config(['ai.providers.openai_compatible.model' => 'gpt-test']);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('AI is not configured for this environment.');

        (new OpenAiCompatibleProvider)->generate(AiOperation::Improve, 'text', AiTone::Formal);
    }

    public function test_the_request_bounds_the_providers_response_with_max_tokens(): void
    {
        config([
            'ai.providers.openai_compatible.api_key' => 'test-key',
            'ai.providers.openai_compatible.endpoint' => 'https://ai.test/v1/chat/completions',
            'ai.providers.openai_compatible.model' => 'gpt-test',
            'ai.max_output_tokens' => 500,
        ]);

        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Improved.']]],
            ], 200),
        ]);

        (new OpenAiCompatibleProvider)->generate(AiOperation::Improve, 'text', AiTone::Formal);

        Http::assertSent(function ($request): bool {
            return $request['max_tokens'] === 500 && $request['model'] === 'gpt-test';
        });
    }

    public function test_a_provider_connection_failure_surfaces_as_ai_provider_exception(): void
    {
        config([
            'ai.providers.openai_compatible.api_key' => 'test-key',
            'ai.providers.openai_compatible.endpoint' => 'https://ai.test/v1/chat/completions',
            'ai.providers.openai_compatible.model' => 'gpt-test',
        ]);

        Http::fake([
            'ai.test/*' => Http::response(['error' => 'server error'], 500),
        ]);

        $this->expectException(AiProviderException::class);

        (new OpenAiCompatibleProvider)->generate(AiOperation::Improve, 'text', AiTone::Formal);
    }
}
