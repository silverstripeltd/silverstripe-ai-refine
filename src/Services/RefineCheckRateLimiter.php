<?php

namespace SilverstripeLtd\AiRefine\Services;

use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;

/**
 * Tracks per-session Refine checks for one member and record pair.
 */
class RefineCheckRateLimiter
{
    private const SESSION_KEY = 'SilverstripeLtd.AiRefine.CheckRateLimiter';

    /**
     * Returns retry-after seconds when the request is throttled, or zero when it is allowed.
     */
    public function consumeRequest(Session $session, int $memberId, int $recordId): int
    {
        $windowSeconds = $this->getWindowSeconds();
        $counterKey = $this->buildCounterKey($memberId, $recordId);
        $timestamps = $this->getTimestamps($session, $counterKey);
        $now = time();
        $windowStart = $now - $windowSeconds;
        $timestamps = $this->pruneTimestamps($timestamps, $windowStart);
        if (count($timestamps) >= $this->getMaxRequests()) {
            $this->setTimestamps($session, $counterKey, $timestamps);
            return max(1, ($timestamps[0] + $windowSeconds) - $now);
        }

        $timestamps[] = $now;
        $this->setTimestamps($session, $counterKey, $timestamps);
        return 0;
    }

    private function buildCounterKey(int $memberId, int $recordId): string
    {
        return sprintf('%d:%d', $memberId, $recordId);
    }

    private function getMaxRequests(): int
    {
        return max(1, (int) Config::inst()->get(RefineCheckRateLimiter::class, 'max_requests'));
    }

    private function getWindowSeconds(): int
    {
        return max(1, (int) Config::inst()->get(RefineCheckRateLimiter::class, 'window_seconds'));
    }

    /**
     * @return array<int, int>
     */
    private function getTimestamps(Session $session, string $counterKey): array
    {
        $rateLimitState = $session->get(self::SESSION_KEY);
        $hasCounterState = is_array($rateLimitState)
            && isset($rateLimitState[$counterKey])
            && is_array($rateLimitState[$counterKey]);
        if (!$hasCounterState) {
            return [];
        }
        return $this->normaliseTimestamps($rateLimitState[$counterKey]);
    }

    /**
     * @param array<int, int> $timestamps
     */
    private function setTimestamps(Session $session, string $counterKey, array $timestamps): void
    {
        $rateLimitState = $session->get(self::SESSION_KEY);
        if (!is_array($rateLimitState)) {
            $rateLimitState = [];
        }
        $rateLimitState[$counterKey] = $timestamps;
        $session->set(self::SESSION_KEY, $rateLimitState);
    }

    /**
     * @param array<int, mixed> $timestamps
     * @return array<int, int>
     */
    private function normaliseTimestamps(array $timestamps): array
    {
        $normalised = [];
        foreach ($timestamps as $timestamp) {
            $value = (int) $timestamp;
            if ($value > 0) {
                $normalised[] = $value;
            }
        }
        sort($normalised);
        return $normalised;
    }

    /**
     * @param array<int, int> $timestamps
     * @return array<int, int>
     */
    private function pruneTimestamps(array $timestamps, int $windowStart): array
    {
        return array_values(array_filter(
            $timestamps,
            static fn(int $timestamp): bool => $timestamp > $windowStart
        ));
    }
}
