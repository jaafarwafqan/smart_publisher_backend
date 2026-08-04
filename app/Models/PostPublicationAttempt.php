<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $claimed_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $processed_at
 */
#[Fillable([
    'post_id',
    'publish_batch_key',
    'organization_id',
    'social_account_id',
    'social_page_id',
    'idempotency_key',
    'attempt_number',
    'status',
    'claimed_at',
    'claimed_by',
    'next_attempt_at',
    'provider_response',
    'provider_request_id',
    'error_message',
    'error_classification',
    'processed_at',
])]
class PostPublicationAttempt extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'claimed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** @return BelongsTo<SocialPage, $this> */
    public function socialPage(): BelongsTo
    {
        return $this->belongsTo(SocialPage::class);
    }
}
