<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Same idempotency shape as BillingWebhookEvent — see that model and this
 * table's migration for why there's no BelongsToOrganization here.
 *
 * @property array<string, mixed> $payload
 */
#[Fillable([
    'provider',
    'provider_event_id',
    'type',
    'payload',
    'social_account_id',
    'social_page_id',
    'organization_id',
    'processed_at',
    'processing_error',
])]
class PlatformWebhookEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
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
