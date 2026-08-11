<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $meta */
#[Fillable([
    'post_id',
    'user_id',
    'organization_id',
    'type',
    'collection',
    'disk',
    'path',
    'thumbnail_path',
    'mime_type',
    'size',
    'width',
    'height',
    'duration_seconds',
    'meta',
    'tags',
    'content_hash',
    'idempotency_key',
])]
class MediaAttachment extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'tags' => 'array',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
