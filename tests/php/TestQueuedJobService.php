<?php

namespace SilverstripeLtd\AiRefine\Tests;

use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queue service double that records jobs instead of dispatching them.
 */
class TestQueuedJobService extends QueuedJobService
{
    /**
     * @var list<array{job: QueuedJob, startAfter: ?string, userId: ?int, queueName: ?int}>
     */
    public array $queuedJobs = [];

    /**
     * Creates the in-memory queue service test double.
     */
    public function __construct()
    {
    }

    /**
     * Stores queued job calls so tests can assert requeue behaviour.
     */
    public function queueJob($job, $startAfter = null, $userId = null, $queueName = null)
    {
        $this->queuedJobs[] = [
            'job' => $job,
            'startAfter' => $startAfter,
            'userId' => $userId,
            'queueName' => $queueName,
        ];
        return count($this->queuedJobs);
    }
}
