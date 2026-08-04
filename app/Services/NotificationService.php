<?php

namespace App\Services;

use App\Enums\OrganizationPermission;
use App\Models\Notification;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * Creates the small set of user-facing lifecycle notifications the product
 * actually supports. This is intentionally persistence-only: delivery
 * channels (push/email) can subscribe later without changing the publishing
 * and approval paths again.
 */
class NotificationService
{
    public function approvalRequested(Post $post): void
    {
        $organizationId = $this->organizationIdFor($post);
        $authorName = $post->user->name;
        $action = $post->approval_requested_action === 'schedule'
            ? 'schedule'
            : 'publish';

        OrganizationMembership::query()
            ->with('user')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get()
            ->each(function (OrganizationMembership $membership) use ($post, $authorName, $action): void {
                $role = $membership->role;
                $recipient = $membership->user;

                if (! $role->hasPermission(OrganizationPermission::PostsApprove)) {
                    return;
                }

                $this->createForUser(
                    $recipient,
                    $post,
                    'post.approval_requested',
                    'Approval requested',
                    $authorName.' requested approval to '.$action.' “'.$post->title.'”.',
                    ['requested_action' => $post->approval_requested_action],
                );
            });
    }

    public function approvalApproved(Post $post, User $approver): ?Notification
    {
        return $this->createForPostAuthor(
            $post,
            'post.approved',
            'Post approved',
            $approver->name.' approved “'.$post->title.'”.',
            ['approved_by' => (int) $approver->id],
        );
    }

    public function approvalRejected(Post $post, User $approver, ?string $note = null): ?Notification
    {
        $body = $approver->name.' rejected “'.$post->title.'”.';
        if ($note !== null && $note !== '') {
            $body .= ' Note: '.$note;
        }

        return $this->createForPostAuthor(
            $post,
            'post.rejected',
            'Post rejected',
            $body,
            ['rejected_by' => (int) $approver->id],
        );
    }

    public function publicationSucceeded(Post $post): ?Notification
    {
        return $this->createForPostAuthor(
            $post,
            'post.publish_succeeded',
            'Post published',
            '“'.$post->title.'” was published successfully.',
        );
    }

    public function publicationFailed(Post $post): ?Notification
    {
        return $this->createForPostAuthor(
            $post,
            'post.publish_failed',
            'Publishing failed',
            '“'.$post->title.'” could not be published. Review its error details before retrying.',
        );
    }

    public function publicationPartiallySucceeded(Post $post): ?Notification
    {
        return $this->createForPostAuthor(
            $post,
            'post.publish_partial_success',
            'Post partially published',
            '“'.$post->title.'” was published to some targets but failed on others. Review which pages/channels need attention.',
        );
    }

    public function publicationCancelled(Post $post): ?Notification
    {
        return $this->createForPostAuthor(
            $post,
            'post.publish_cancelled',
            'Publishing cancelled',
            '“'.$post->title.'” was cancelled before it was published.',
        );
    }

    public function retryExhausted(Post $post, ?int $attemptId = null): ?Notification
    {
        return $this->createForPostAuthor(
            $post,
            'post.retry_exhausted',
            'Publishing retries exhausted',
            '“'.$post->title.'” exhausted its publishing retry budget and needs attention.',
            array_filter(['publication_attempt_id' => $attemptId]),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createForPostAuthor(
        Post $post,
        string $type,
        string $title,
        string $body,
        array $data = [],
    ): ?Notification {
        return $this->createForUser($post->user, $post, $type, $title, $body, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createForUser(
        User $recipient,
        Post $post,
        string $type,
        string $title,
        string $body,
        array $data = [],
    ): ?Notification {
        $organizationId = $this->organizationIdFor($post);

        // A user who has left this organization must not receive (or retain
        // access to) new tenant notifications from an in-flight job.
        if (! $recipient->isMemberOf($organizationId)) {
            return null;
        }

        return Notification::query()->create([
            'user_id' => $recipient->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => array_merge([
                'post_id' => (int) $post->id,
            ], $data),
        ]);
    }

    private function organizationIdFor(Post $post): int
    {
        $organizationId = app(TenantContext::class)->get();

        if ((int) $post->organization_id !== $organizationId) {
            throw new LogicException('Notification lifecycle event crossed organization boundaries.');
        }

        return $organizationId;
    }
}
