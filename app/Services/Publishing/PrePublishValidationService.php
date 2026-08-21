<?php

namespace App\Services\Publishing;

use App\Models\Post;
use App\Models\SocialPage;
use App\Support\Publishing\ClosedBetaPublishingGate;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * One report shape for the composer and the final server-side publish gate.
 * Warnings never replace the existing authoritative publish checks; errors do.
 */
final class PrePublishValidationService
{
    public function __construct(private readonly ClosedBetaPublishingGate $closedBetaGate) {}

    /**
     * $requireTargets is false for Post::schedule() — a post can already be
     * scheduled (and, before that, sent for approval) with no page selected
     * yet; that has been this codebase's own deliberate, pre-existing
     * behavior since ClosedBetaPublishingGate's own target-set assertions
     * are themselves no-ops for an empty page collection. publishNow() and
     * the composer's own advisory check both keep requiring a target — only
     * an actual, immediate publish attempt needs one right now.
     *
     * @param  list<int>|null  $requestedPageIds
     * @return array{errors: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>, notices: list<array{code: string, message: string}>}
     */
    public function check(Post $post, ?array $requestedPageIds = null, bool $requireTargets = true): array
    {
        $errors = [];
        $warnings = [];
        $notices = [];
        $content = trim((string) $post->content);
        $title = trim((string) $post->title);
        $pageIds = $requestedPageIds ?? $post->socialPages()->pluck('social_pages.id')->map(fn ($id): int => (int) $id)->all();

        if ($title === '' && $content === '') {
            $errors[] = $this->item('post_content_required', 'A title or post content is required.');
        }
        if ($pageIds === [] && $requireTargets) {
            $errors[] = $this->item('publish_target_required', 'Choose at least one usable publishing target.');
        }

        $pages = SocialPage::query()->with('socialAccount')->whereIn('id', $pageIds)->get();
        if ($pages->count() !== count($pageIds)) {
            $errors[] = $this->item('invalid_target', 'One or more selected targets are unavailable in this organization.');
        }
        foreach ($pages as $page) {
            if (! $page->isUsable()) {
                $errors[] = $this->item('target_unusable', 'One or more selected targets are disconnected or cannot publish.');
                break;
            }
        }

        try {
            $this->closedBetaGate->assertPagesAllowed($pages);
            $this->closedBetaGate->assertMediaSupportedByTargets($post, $pages);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $errors[] = $this->item('platform_capability', (string) $message);
                }
            }
        }

        foreach ($pages as $page) {
            $provider = $page->kind === 'instagram_business' ? 'instagram' : strtolower((string) $page->socialAccount?->provider);
            if ($provider === 'telegram' && mb_strlen($content) > 4096) {
                $errors[] = $this->item('telegram_caption_too_long', 'Telegram messages are limited to 4096 characters.');
                break;
            }
        }

        if (preg_match('/https?:\/\/\s/iu', $content) === 1) {
            $errors[] = $this->item('invalid_link', 'The post contains an invalid link.');
        }
        if (preg_match('/(?:^|\s)(#[^\s]+)(?=.*(?:^|\s)\1)(?=\s|$)/u', $content) === 1) {
            $warnings[] = $this->item('duplicate_hashtag', 'The post appears to repeat a hashtag.');
        }
        if ($content !== '' && Post::query()
            ->where('organization_id', $post->organization_id)
            ->where('content', $post->content)
            ->whereKeyNot($post->getKey())
            ->exists()) {
            $warnings[] = $this->item('possible_duplicate_content', 'An identical post already exists in this organization.');
        }
        $scheduledAt = $post->getAttribute('scheduled_at');
        if ($scheduledAt instanceof CarbonInterface && $scheduledAt->isPast()) {
            $errors[] = $this->item('schedule_in_past', 'The scheduled publishing time must be in the future.');
        }
        if ($post->mediaAttachments()->count() === 0) {
            $notices[] = $this->item('no_media', 'No media is attached; this is allowed for the selected targets.');
        }

        return compact('errors', 'warnings', 'notices');
    }

    /** @param list<int>|null $requestedPageIds */
    public function assertNoBlockingErrors(Post $post, ?array $requestedPageIds = null, bool $requireTargets = true): void
    {
        $report = $this->check($post, $requestedPageIds, $requireTargets);
        if ($report['errors'] === []) {
            return;
        }

        throw ValidationException::withMessages([
            'pre_publish' => array_map(fn (array $item): string => $item['message'], $report['errors']),
        ]);
    }

    /** @return array{code: string, message: string} */
    private function item(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
