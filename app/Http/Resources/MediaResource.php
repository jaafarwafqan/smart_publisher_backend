<?php

namespace App\Http\Resources;

use App\Models\MediaAttachment;
use App\Support\Media\PublicMediaUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->media();

        return [
            'id' => (int) $media->id,
            'post_id' => $media->post_id !== null ? (int) $media->post_id : null,
            'user_id' => (int) $media->user_id,
            'type' => (string) $media->type,
            'collection' => (string) $media->collection,
            'disk' => (string) $media->disk,
            'path' => (string) $media->path,
            'url' => $media->disk && $media->path
                ? $this->mediaUrl($media, $media->path)
                : null,
            // Flutter's MediaResponseDtoV1 has always read this field
            // directly as a fetchable URL (thumbnailUrl: json['thumbnail_path']),
            // never as a raw disk-relative path — so on the 'local' disk it
            // was exactly as broken as the main 'url' field was, just
            // silently, since nothing asserted it worked. Same fix, same
            // disk (thumbnails have no disk column of their own; they are
            // always written to $media->disk alongside the original file).
            'thumbnail_path' => $media->thumbnail_path && $media->disk
                ? $this->mediaUrl($media, $media->thumbnail_path)
                : null,
            'mime_type' => $media->mime_type,
            'size' => (int) $media->size,
            'width' => $media->width !== null ? (int) $media->width : null,
            'height' => $media->height !== null ? (int) $media->height : null,
            'duration_seconds' => $media->duration_seconds !== null
                ? (float) $media->duration_seconds
                : null,
            'tags' => $media->tags ?? [],
            'meta' => $media->meta ?? [],
            'created_at' => optional($media->created_at)?->toIso8601String(),
            'updated_at' => optional($media->updated_at)?->toIso8601String(),
        ];
    }

    private function media(): MediaAttachment
    {
        if (! $this->resource instanceof MediaAttachment) {
            throw new LogicException('MediaResource requires a MediaAttachment model.');
        }

        return $this->resource;
    }

    /**
     * Sprint 2 (API Hardening): the default 'local' disk
     * (storage_path('app/private'), FILESYSTEM_DISK=local) is intentionally
     * NOT public — Laravel's own built-in storage.local route (ServeFile)
     * requires either 'visibility' => 'public' or a valid signed URL, and
     * rejects a plain unsigned request with 403. A bare Storage::url() call
     * on this disk therefore returned a URL that 403'd on every single
     * request — every uploaded file was effectively undownloadable through
     * this field, confirmed live via a direct request to the exact
     * generated URL. A signed, time-limited temporaryUrl() is both the
     * correct fix (it actually works) and the safer one (it expires,
     * unlike a bare unsigned public URL would be).
     */
    private function mediaUrl(MediaAttachment $media, string $path): string
    {
        return PublicMediaUrlResolver::resolve($media->disk, $path);
    }
}
