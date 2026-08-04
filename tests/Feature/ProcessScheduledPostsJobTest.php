<?php

namespace Tests\Feature;

use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessScheduledPostsJobTest extends TestCase
{
    use RefreshDatabase;

    private function usablePageFor(User $user): SocialPage
    {
        $account = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_account_id' => 'ig-1',
            'access_token' => 'token',
            'status' => 'connected',
            'is_active' => true,
        ]);

        return SocialPage::query()->create([
            'social_account_id' => $account->id,
            'page_id' => 'ig-page-1',
            'name' => 'Instagram Page',
            'can_publish' => true,
            'status' => 'valid',
        ]);
    }

    public function test_it_moves_a_due_post_out_of_scheduled_before_dispatching(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        [$post] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Due Post',
                'status' => 'scheduled',
                'scheduled_at' => now()->subMinute(),
            ]);

            $post->socialPages()->sync([$this->usablePageFor($user)->id]);

            return [$post];
        });

        (new ProcessScheduledPostsJob)->handle();

        $attempt = $this->asOrganizationOf($user, function () use ($post): PostPublicationAttempt {
            $post->refresh();
            $this->assertNotSame('scheduled', $post->status);
            $this->assertSame('publishing', $post->status);

            return PostPublicationAttempt::query()
                ->where('post_id', $post->id)
                ->firstOrFail();
        });
        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($post, $attempt): bool {
            return $job->postId === $post->id
                && $job->publicationAttemptId === $attempt->id
                && $job->publishBatchKey === $attempt->publish_batch_key;
        });
    }

    public function test_running_it_twice_before_the_dispatched_jobs_process_does_not_duplicate_dispatch(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->asOrganizationOf($user, function () use ($user): void {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Due Post',
                'status' => 'scheduled',
                'scheduled_at' => now()->subMinute(),
            ]);

            $post->socialPages()->sync([$this->usablePageFor($user)->id]);
        });

        // Simulates the scheduler firing again a minute later before any of
        // the previously-dispatched PublishPostJobs have been processed.
        (new ProcessScheduledPostsJob)->handle();
        (new ProcessScheduledPostsJob)->handle();

        Queue::assertPushed(PublishPostJob::class, 1);
    }

    public function test_a_due_post_with_no_usable_target_pages_is_marked_failed_not_publishing(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $post = $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Due Post No Accounts',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]));

        (new ProcessScheduledPostsJob)->handle();

        $this->asOrganizationOf($user, function () use ($post): void {
            $post->refresh();
            $this->assertSame('failed', $post->status);
        });
        Queue::assertNotPushed(PublishPostJob::class);
    }
}
