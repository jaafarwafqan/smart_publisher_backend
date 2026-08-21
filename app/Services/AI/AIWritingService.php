<?php

namespace App\Services\AI;

use App\Contracts\AI\AiProviderInterface;
use App\Enums\AiOperation;
use App\Enums\AiTone;
use App\Exceptions\AiProviderException;
use App\Models\AiUsageLog;
use App\Models\Post;
use App\Models\User;
use App\Services\ContextLogger;
use Illuminate\Http\Request;

/** Coordinates privacy protection, provider invocation, sanitization, and audit-safe usage logging. */
final class AIWritingService
{
    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  list<string>  $platforms
     * @return array{original_text: string, proposed_text: string, suggestions: list<string>, provider: string}
     */
    public function generate(
        Request $request,
        User $user,
        AiOperation $operation,
        string $text,
        AiTone $tone,
        ?string $targetLanguage = null,
        array $platforms = [],
        ?Post $post = null,
    ): array {
        $startedAt = microtime(true);
        $redacted = $this->redactor->redact($text);
        $output = null;

        try {
            $output = $this->provider->generate($operation, $redacted, $tone, $targetLanguage, $platforms);
            if ($this->requiresTokenPreservation($operation) && ! $this->redactor->hasAllPlaceholders($output)) {
                throw new AiProviderException('The AI response omitted protected text, so no change was applied.');
            }
            $proposed = $this->sanitizeOutput($this->redactor->restore($output));

            if ($proposed === '') {
                throw new \RuntimeException('AI output was empty after sanitization.');
            }

            $result = [
                'original_text' => $text,
                'proposed_text' => $proposed,
                'suggestions' => $this->suggestionsFor($operation, $proposed),
                'provider' => $this->provider->name(),
            ];

            $this->logUsage($request, $user, $post, $operation, 'succeeded', $startedAt, $text, $proposed);
            ContextLogger::info('ai.request.succeeded', [
                'operation' => $operation->value,
                'provider' => $this->provider->name(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'input_characters' => mb_strlen($text),
                'output_characters' => mb_strlen($proposed),
            ], $request);

            return $result;
        } catch (\Throwable $exception) {
            $this->logUsage($request, $user, $post, $operation, 'failed', $startedAt, $text, '');
            ContextLogger::warning('ai.request.failed', [
                'operation' => $operation->value,
                'provider' => $this->provider->name(),
                'exception' => $exception::class,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'input_characters' => mb_strlen($text),
            ], $request);

            throw $exception;
        }
    }

    private function sanitizeOutput(string $output): string
    {
        $withoutTags = strip_tags($output);
        $withoutControls = preg_replace('/[\p{Cc}&&[^\n\r\t]]/u', '', $withoutTags) ?? $withoutTags;
        $trimmed = trim($withoutControls);

        return mb_substr($trimmed, 0, (int) config('ai.max_text_characters', 10000));
    }

    /** @return list<string> */
    private function suggestionsFor(AiOperation $operation, string $proposed): array
    {
        if (! in_array($operation, [
            AiOperation::SuggestTitles,
            AiOperation::SuggestClosing,
            AiOperation::SuggestCallToAction,
            AiOperation::SuggestHashtags,
        ], true)) {
            return [];
        }

        return collect(preg_split('/\R/u', $proposed) ?: [])
            ->map(fn (string $line): string => trim(preg_replace('/^[\-*•\d.)\s]+/u', '', $line) ?? $line))
            ->filter()
            ->unique()
            ->take($operation === AiOperation::SuggestHashtags ? 12 : 5)
            ->values()
            ->all();
    }

    private function logUsage(
        Request $request,
        User $user,
        ?Post $post,
        AiOperation $operation,
        string $status,
        float $startedAt,
        string $input,
        string $output,
    ): void {
        AiUsageLog::query()->create([
            'user_id' => $user->id,
            'post_id' => $post?->id,
            'operation' => $operation->value,
            'provider' => $this->provider->name(),
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'input_characters' => mb_strlen($input),
            'output_characters' => mb_strlen($output),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    private function requiresTokenPreservation(AiOperation $operation): bool
    {
        return ! in_array($operation, [
            AiOperation::SuggestTitles,
            AiOperation::SuggestClosing,
            AiOperation::SuggestCallToAction,
            AiOperation::SuggestHashtags,
        ], true);
    }
}
