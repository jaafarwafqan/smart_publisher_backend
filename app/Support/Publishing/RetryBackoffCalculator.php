<?php

namespace App\Support\Publishing;

/**
 * Exponential backoff with jitter — replaces the old fixed [10, 30, 60]
 * schedule. Jitter matters here specifically because a scheduled batch can
 * fail dozens of attempts in the same tick (e.g. a provider outage hitting
 * every post targeting it at once); without jitter they'd all retry at the
 * exact same moment and hit the provider in another synchronized wave.
 */
class RetryBackoffCalculator
{
    public function __construct(
        private readonly int $baseSeconds = 10,
        private readonly int $maxSeconds = 900,
        private readonly float $jitterRatio = 0.3,
    ) {}

    /**
     * $attemptNumber is 1-indexed (the attempt that just failed).
     */
    public function secondsFor(int $attemptNumber): int
    {
        $exponential = $this->baseSeconds * (2 ** max($attemptNumber - 1, 0));
        $capped = min($exponential, $this->maxSeconds);

        $jitterSpan = (int) round($capped * $this->jitterRatio);
        $jitter = $jitterSpan > 0 ? random_int(-$jitterSpan, $jitterSpan) : 0;

        return max($capped + $jitter, 1);
    }
}
