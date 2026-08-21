<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $status
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $canceled_at
 * @property string|null $provider_customer_id
 * @property int|null $granted_by_user_id
 * @property string|null $granted_reason
 * @property-read Plan|null $plan
 */
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
    'granted_by_user_id',
    'granted_reason',
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

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /**
     * Prepaid-billing model (2026-08-21): none of the Iraqi gateways this
     * product integrates with (FIB, ZainCash, Qi Card) support recurring
     * subscriptions — every successful payment is a one-time charge that
     * extends current_period_end by the plan's paid-for month count (see
     * BillingPeriodGrantService). Checking only $status therefore used to
     * mean an expired prepaid period stayed "active" forever, since nothing
     * ever flips the status string on its own — billing:expire-subscriptions
     * is the only thing that does, and only once a day. Requiring
     * current_period_end to be null (an unbounded manual grant, or a
     * subscription that predates this column entirely) or still in the
     * future is what actually enforces the prepaid period.
     */
    public function isActiveOrTrialing(): bool
    {
        if (! in_array($this->status, ['active', 'trialing'], true)) {
            return false;
        }

        return $this->current_period_end === null || $this->current_period_end->isFuture();
    }
}
