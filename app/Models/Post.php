<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $meta
 */
#[Fillable([
    'user_id',
    'branch_id',
    'organization_id',
    'title',
    'content',
    'status',
    'scheduled_at',
    'published_at',
    'failed_at',
    'last_error',
    'meta',
    'publish_batch_key',
    'approval_status',
    'approval_requested_action',
    'approval_requested_scheduled_at',
    'approved_by',
    'approved_at',
    'approval_note',
    'idempotency_key',
])]
class Post extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
            'approval_requested_scheduled_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<MediaAttachment, $this> */
    public function mediaAttachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /** @return HasMany<PostPublicationAttempt, $this> */
    public function publicationAttempts(): HasMany
    {
        return $this->hasMany(PostPublicationAttempt::class);
    }

    /** @return HasMany<PostTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /** @return BelongsToMany<SocialPage, $this, PostTarget, 'pivot'> */
    public function socialPages(): BelongsToMany
    {
        // Sprint H (role/permission remediation, 2026-08-09): ->using()
        // routes attach()/sync() writes through PostTarget's own model
        // events (see its BelongsToOrganization usage) instead of a raw
        // query-builder insert — the only way its organization_id column
        // gets stamped automatically, including by every existing
        // ->sync(...) call site.
        return $this->belongsToMany(SocialPage::class, 'post_targets')->using(PostTarget::class);
    }

    /** @return HasMany<PostMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(PostMetric::class);
    }
}
