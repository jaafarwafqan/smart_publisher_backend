<?php

namespace App\Support\Publishing;

use App\Exceptions\Publishing\IllegalStateTransitionException;
use App\Models\Post;

/**
 * Post.status transitions (draft/scheduled/publishing/published/failed),
 * driven by config('publishing.allowed_status_transitions') — that config
 * already existed but was never actually enforced anywhere (confirmed via
 * project-wide grep) and didn't even list 'publishing' as a valid state.
 * Every call site that used to do a raw
 * `$post->update(['status' => $x, ...])` now goes through here instead.
 */
class PostStateMachine
{
    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, config("publishing.allowed_status_transitions.{$from}", []), true);
    }

    /**
     * @param  array<string, mixed>  $attributes  extra columns to set alongside the status change
     */
    public function transition(Post $post, string $to, array $attributes = []): void
    {
        if (! $this->canTransition($post->status, $to)) {
            throw new IllegalStateTransitionException(
                "Cannot transition post #{$post->id} from '{$post->status}' to '{$to}'."
            );
        }

        $post->update(array_merge(['status' => $to], $attributes));
    }
}
