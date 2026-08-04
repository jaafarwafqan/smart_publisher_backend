<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\RetryDeadLetteredAttemptJob;
use App\Models\DeadLetterJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublishingController extends Controller
{
    public function runSchedulerTick(): JsonResponse
    {
        ProcessScheduledPostsJob::dispatch();

        return response()->json([
            'message' => 'Scheduled posts processing job dispatched.',
        ]);
    }

    public function deadLetters(): JsonResponse
    {
        return response()->json(DeadLetterJob::query()->latest('failed_at')->paginate(20));
    }

    /**
     * Manual DLQ retry. DeadLetterJob is route-model-bound and tenant-scoped
     * (BelongsToOrganization), so a dead letter belonging to another
     * organization 404s here before any of this logic runs — satisfying the
     * "switching organization never reveals another organization's attempts
     * or errors" acceptance criterion.
     *
     * The retried_at/retried_by claim below is the audit trail AND the
     * concurrency guard: it's a conditional UPDATE with an affected-rows
     * check, same pattern as every other atomic claim in Sprint 3, so two
     * admins clicking retry on the same row at the same moment still only
     * ever dispatch one retry job.
     */
    public function retryDeadLetter(Request $request, DeadLetterJob $deadLetterJob): JsonResponse
    {
        if ($deadLetterJob->reference_type !== 'post_publication_attempt') {
            return response()->json([
                'message' => 'Only publish-attempt dead letters can be retried from this endpoint.',
            ], 422);
        }

        $claimed = DeadLetterJob::query()
            ->where('id', $deadLetterJob->id)
            ->whereNull('retried_at')
            ->update([
                'retried_at' => now(),
                'retried_by' => $request->user()->id,
            ]);

        if ($claimed !== 1) {
            return response()->json([
                'message' => 'This dead-letter entry has already been retried.',
            ], 409);
        }

        RetryDeadLetteredAttemptJob::dispatch(
            (int) $deadLetterJob->reference_id,
            (int) $deadLetterJob->organization_id,
        );

        return response()->json([
            'message' => 'Dead-letter entry queued for retry.',
        ]);
    }

    public function clearProviderCircuit(Request $request, PublishEngineService $engine): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
        ]);

        $engine->clearProviderFailures($validated['provider']);

        return response()->json([
            'message' => 'Provider circuit breaker state cleared.',
            'provider' => $validated['provider'],
        ]);
    }
}
