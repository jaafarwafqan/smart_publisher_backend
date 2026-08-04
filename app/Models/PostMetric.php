<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'post_id',
    'organization_id',
    'social_page_id',
    'provider',
    'is_available',
    'impressions',
    'reach',
    'clicks',
    'reactions',
    'shares',
    'comments',
    'raw_response',
    'fetched_at',
])]
class PostMetric extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'raw_response' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialPage(): BelongsTo
    {
        return $this->belongsTo(SocialPage::class);
    }

    /**
     * Not stored directly — computed on read so it can never drift from its
     * components as new metric types are added.
     */
    public function getEngagementAttribute(): int
    {
        return $this->reactions + $this->shares + $this->comments + $this->clicks;
    }
}
