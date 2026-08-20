<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'plan_id',
    'status',
    'current_period_start',
    'current_period_end',
    'trial_ends_at',
    'canceled_at',
    'provider_subscription_id',
    'provider_customer_id',
])]
class OrganizationSubscription extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActiveOrTrialing(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }
}
