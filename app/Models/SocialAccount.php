<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon|null $token_expires_at */
#[Fillable([
    'user_id',
    'organization_id',
    'provider',
    'discovery_mode',
    'provider_account_id',
    'account_name',
    'account_username',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'scopes',
    'metadata',
    'is_active',
    'status',
    'last_synced_at',
    'last_published_at',
])]
class SocialAccount extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PostPublicationAttempt, $this> */
    public function publicationAttempts(): HasMany
    {
        return $this->hasMany(PostPublicationAttempt::class);
    }

    /** @return HasMany<SocialPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(SocialPage::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at?->isPast() ?? false;
    }

    public function hasRefreshToken(): bool
    {
        return ! empty($this->refresh_token);
    }

    public function isAutoDiscovery(): bool
    {
        return $this->discovery_mode === 'auto';
    }
}
