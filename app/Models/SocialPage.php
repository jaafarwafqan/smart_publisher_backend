<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'social_account_id',
    'organization_id',
    'page_id',
    'kind',
    'name',
    'username',
    'picture_url',
    'can_publish',
    'is_selected',
    'status',
    'discovery_source',
    'metadata',
    'last_synced_at',
    'last_verified_at',
])]
class SocialPage extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'can_publish' => 'boolean',
            'is_selected' => 'boolean',
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function postTargets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function isUsable(): bool
    {
        return $this->can_publish && $this->status === 'valid';
    }
}
