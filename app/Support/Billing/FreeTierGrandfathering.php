<?php

namespace App\Support\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads pre-billing tenant usage without mutating data.
 *
 * The same counting rules are used by the runtime quota checkpoints. A
 * legacy organization is grandfathered only when its existing usage is
 * already strictly above Free; an organization exactly at a Free limit keeps
 * that plan and may manage capacity normally.
 */
final class FreeTierGrandfathering
{
    /**
     * @return array{
     *     max_team_members: int,
     *     max_social_accounts: int,
     *     max_scheduled_posts_per_month: int
     * }
     */
    public function usageFor(int $organizationId, ?CarbonInterface $periodStart = null): array
    {
        $periodStart ??= now()->startOfMonth();

        return [
            QuotaGates::TEAM_MEMBERS => DB::table('organization_memberships')
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->count(),
            QuotaGates::SOCIAL_ACCOUNTS => DB::table('social_accounts')
                ->where('organization_id', $organizationId)
                ->count(),
            QuotaGates::SCHEDULED_POSTS_PER_MONTH => DB::table('posts')
                ->where('organization_id', $organizationId)
                ->whereIn('status', ['scheduled', 'publishing', 'published'])
                ->where('created_at', '>=', $periodStart)
                ->count(),
        ];
    }

    /**
     * @param  array<string, int>  $usage
     * @param  array<string, int|null>  $limits
     */
    public function exceedsLimits(array $usage, array $limits): bool
    {
        foreach (QuotaGates::all() as $key) {
            $limit = array_key_exists($key, $limits)
                ? $limits[$key]
                : QuotaGates::fallbackFor($key);

            if ($limit !== null && ($usage[$key] ?? 0) > $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     usage: array<string, int>,
     *     exceeds_free_limits: bool
     * }>
     */
    public function auditOrganizationsWithoutSubscriptions(?CarbonInterface $periodStart = null): array
    {
        $periodStart ??= now()->startOfMonth();
        $freeLimits = QuotaGates::fallbackLimits();

        return DB::table('organizations')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('organization_subscriptions')
                    ->whereColumn('organization_subscriptions.organization_id', 'organizations.id');
            })
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(function (object $organization) use ($periodStart, $freeLimits): array {
                $usage = $this->usageFor((int) $organization->id, $periodStart);

                return [
                    'id' => (int) $organization->id,
                    'name' => (string) $organization->name,
                    'usage' => $usage,
                    'exceeds_free_limits' => $this->exceedsLimits($usage, $freeLimits),
                ];
            })
            ->all();
    }
}
