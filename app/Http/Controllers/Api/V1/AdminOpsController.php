<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Ops\OpsHealthSnapshot;
use Illuminate\Http\JsonResponse;

/**
 * Phase 4 (observability, 2026-08-16): an on-demand read of the exact same
 * real metrics `app:ops-snapshot` already computes every 5 minutes via the
 * scheduler — see OpsHealthSnapshot's own docblock for why the computation
 * lives in one shared place. Platform-only (super_admin), same as every
 * other /admin/* route: never enters the tenant middleware group.
 */
class AdminOpsController extends Controller
{
    public function show(OpsHealthSnapshot $snapshot): JsonResponse
    {
        $data = $snapshot->compute();

        $breaches = [
            'queue_length' => $data['queue_length'] >= (int) $data['thresholds']['queue_length'],
            'publish_failure_rate' => $data['publish_failure_sample_size'] > 0
                && $data['publish_failure_rate'] >= (float) $data['thresholds']['publish_failure_rate'],
            'retry_storm_count' => $data['retry_storm_count'] >= (int) $data['thresholds']['retry_storm_count'],
            'dead_letter_open_count' => $data['dead_letter_open_count'] >= (int) $data['thresholds']['dead_letter_open_count'],
        ];

        return response()->json([
            'data' => [
                ...$data,
                'breaches' => $breaches,
                'healthy' => ! in_array(true, $breaches, true),
                'computed_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
