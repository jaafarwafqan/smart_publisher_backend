<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use LogicException;

class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $post = $this->post();
        $user = $post->relationLoaded('user') ? $post->getRelation('user') : null;
        $branch = $post->relationLoaded('branch') ? $post->getRelation('branch') : null;
        $mediaAttachments = $post->relationLoaded('mediaAttachments')
            ? $post->getRelation('mediaAttachments')
            : null;

        return [
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'content' => $post->content,
            'status' => (string) $post->status,
            'meta' => $this->normalizedMeta($post),
            'publish_batch_key' => $post->publish_batch_key,
            'user' => $user instanceof User
                ? [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ]
                : null,
            'branch' => $branch instanceof Branch
                ? [
                    'id' => (int) $branch->id,
                    'name' => (string) $branch->name,
                    'code' => (string) $branch->code,
                ]
                : null,
            'media_attachments' => $mediaAttachments instanceof Collection
                ? MediaResource::collection($mediaAttachments)
                : [],
            'platforms' => $this->targetPlatforms($post),
            'target_page_ids' => $this->targetPageIds($post),
            'scheduled_at' => $this->isoTimestamp($post->scheduled_at),
            'published_at' => $this->isoTimestamp($post->published_at),
            'failed_at' => $this->isoTimestamp($post->failed_at),
            'created_at' => $this->isoTimestamp($post->created_at),
            'updated_at' => $this->isoTimestamp($post->updated_at),
        ];
    }

    /**
     * `platform_content` is conceptually a JSON object (string => string),
     * but an empty PHP array is indistinguishable from an empty list, so
     * json_encode would otherwise emit `[]` instead of `{}` and break
     * clients that decode it strictly as a map.
     *
     * @return array<string, mixed>
     */
    private function normalizedMeta(Post $post): array
    {
        $meta = $post->meta ?? [];
        $platformContent = $meta['platform_content'] ?? [];
        $meta['platform_content'] = empty($platformContent) ? new \stdClass : $platformContent;

        return $meta;
    }

    /**
     * The real providers this post actually targets, derived from its
     * selected pages — not a fabricated/legacy field. Empty (not an error)
     * when the relation wasn't eager-loaded by the caller.
     *
     * @return array<int, string>
     */
    private function targetPlatforms(Post $post): array
    {
        if (! $post->relationLoaded('socialPages')) {
            return [];
        }

        $pages = $post->getRelation('socialPages');
        if (! $pages instanceof Collection) {
            return [];
        }

        $platforms = [];
        foreach ($pages as $page) {
            if (! $page instanceof SocialPage || ! $page->relationLoaded('socialAccount')) {
                continue;
            }

            $account = $page->getRelation('socialAccount');
            if ($account instanceof SocialAccount) {
                $provider = trim($account->provider);
                if ($provider !== '') {
                    $platforms[] = $provider;
                }
            }
        }

        return array_values(array_unique($platforms));
    }

    /**
     * The page ids this post targets — was missing entirely, which meant
     * fetching a post for edit always got an empty selection, and saving
     * that edit sent `target_page_ids: []` back, silently detaching every
     * page via PostController::update()'s socialPages()->sync([]). Empty
     * (not an error) when the relation wasn't eager-loaded by the caller,
     * matching targetPlatforms()'s convention.
     *
     * @return array<int, int>
     */
    private function targetPageIds(Post $post): array
    {
        if (! $post->relationLoaded('socialPages')) {
            return [];
        }

        $pages = $post->getRelation('socialPages');
        if (! $pages instanceof Collection) {
            return [];
        }

        return $pages->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function isoTimestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function post(): Post
    {
        if (! $this->resource instanceof Post) {
            throw new LogicException('PostResource requires a Post model.');
        }

        return $this->resource;
    }
}
