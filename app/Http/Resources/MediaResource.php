<?php

namespace App\Http\Resources;

use App\Models\MediaAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
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
                ? Storage::disk($media->disk)->url($media->path)
                : null,
            'thumbnail_path' => $media->thumbnail_path,
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
}
