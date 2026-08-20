<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed>|null $limits */
#[Fillable([
    'name',
    'slug',
    'price_cents',
    'billing_interval',
    'currency',
    'stripe_price_id',
    'limits',
    'is_active',
])]
class Plan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<OrganizationSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizationSubscription::class);
    }

    /**
     * A missing key means "unlimited" for that dimension — see
     * OrganizationEntitlements, which is the only place this should be
     * read from in application code.
     *
     * Deliberately NOT named limit() — Eloquent models forward unknown
     * method calls to a query Builder via __call(), and Builder already
     * has a real limit(int $value) (the SQL LIMIT clause). Larastan
     * caught this: naming this method limit() made $plan->limit('key')
     * statically resolve as the SQL builder method, not this one — it
     * happened to still work at runtime (PHP resolves the model's own
     * declared method first), but it's exactly the kind of collision that
     * breaks the moment anyone reads or refactors this expecting the
     * ubiquitous Eloquent meaning of "limit."
     */
    public function usageLimit(string $key): ?int
    {
        $value = ($this->limits ?? [])[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
