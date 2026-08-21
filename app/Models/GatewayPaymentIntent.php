<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * See the owning migration's docblock — the bridge between a checkout
 * a prepaid gateway (FIB, ZainCash) started and the bare payment reference
 * its webhook/callback reports back later.
 */
#[Fillable([
    'gateway',
    'reference',
    'organization_id',
    'plan_id',
    'months',
    'amount',
    'currency',
    'status',
])]
class GatewayPaymentIntent extends Model
{
    use HasFactory;

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
}
