<?php

namespace SilverstripeLtd\AiBrandVoice\Jobs;

use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverstripeLtd\AiBrandVoice\Services\BrandVoiceEvaluationService;
use SilverstripeLtd\AiBrandVoice\Services\ContentExtractionService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\SiteConfig\SiteConfig;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * Queued job that evaluates persisted brand voice compliance for Live pages.
 */
class EvaluateBrandVoiceJob extends AbstractQueuedJob
{
    private const DEFAULT_BATCH_SIZE = 50;
    private const DEFAULT_RATE_LIMIT_DELAY = 6;
    private const DEFAULT_REQUEUE_DELAY = 28800;
    private const ENV_BATCH_SIZE = 'AI_BRAND_VOICE_JOB_BATCH_SIZE';
    private const ENV_RATE_LIMIT_DELAY = 'AI_BRAND_VOICE_RATE_LIMIT_DELAY';
    private const ENV_REQUEUE_DELAY = 'AI_BRAND_VOICE_JOB_REQUEUE_DELAY';

    public array $pageIds = [];
    public int $scanIndex = 0;
    public int $processedCount = 0;
    public int $succeededCount = 0;
    public int $failedCount = 0;
    public int $skippedCount = 0;
    public int $targetedCount = 0;
    public bool $preflightChecked = false;
    public string $brandVoiceDefinition = '';

    private ?BrandVoiceEvaluationService $evaluationService = null;
    private ?ContentExtractionService $contentExtractionService = null;
    private ?LoggerInterface $logger = null;
    private ?QueuedJobService $queuedJobService = null;
    private bool $hasRequeued = false;

    /**
     * @var callable|null
     */
    private $sleepHandler = null;

    /**
     * Seeds the queued job with the current page IDs and progress counters.
     */
    public function __construct($params = [])
    {
        parent::__construct($params);

        $this->pageIds = SiteTree::get()->sort('ID')->column('ID');
        $this->totalSteps = count($this->pageIds);
        $this->currentStep = 0;
    }

    /**
     * Returns the queued job title shown in CMS job listings.
     */
    public function getTitle(): string
    {
        return 'Evaluate brand voice';
    }

    /**
     * Declares that the job should run on the standard queued worker.
     */
    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    /**
     * Injects the evaluation service used to analyse page content.
     */
    public function setEvaluationService(BrandVoiceEvaluationService $service): void
    {
        $this->evaluationService = $service;
    }

    /**
     * Injects the extraction service used to read live page content.
     */
    public function setContentExtractionService(ContentExtractionService $service): void
    {
        $this->contentExtractionService = $service;
    }

    /**
     * Injects the logger used for job progress and failure messages.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Injects the queue service used to schedule the next job instance.
     */
    public function setQueuedJobService(QueuedJobService $service): void
    {
        $this->queuedJobService = $service;
    }

    /**
     * Injects a sleep callback so rate limiting can be controlled in tests.
     */
    public function setSleepHandler(callable $sleepHandler): void
    {
        $this->sleepHandler = $sleepHandler;
    }

    /**
     * Processes one queue run, scanning pages until the current batch is full.
     */
    public function process(): void
    {
        if (!$this->preflightChecked) {
            $this->preflightChecked = true;
            $this->brandVoiceDefinition = $this->getConfiguredBrandVoiceDefinition();
            if ($this->brandVoiceDefinition === '') {
                $this->addMessage('No brand voice definition configured - skipping all pages');
                $this->logger()->info('No brand voice definition configured - skipping all pages');
                $this->finishJob();
                return;
            }
        }

        $batchSize = $this->getBatchSize();
        if ($this->scanIndex >= count($this->pageIds) || $this->targetedCount >= $batchSize) {
            $this->finishJob();
            return;
        }

        while ($this->scanIndex < count($this->pageIds) && $this->targetedCount < $batchSize) {
            $pageId = (int) $this->pageIds[$this->scanIndex];
            $this->scanIndex++;
            $this->currentStep = $this->scanIndex;

            $page = SiteTree::get()->byID($pageId);
            if (!$page) {
                $this->processedCount++;
                $this->failedCount++;
                $this->logger()->warning(sprintf('Brand voice job: page %d not found - skipped', $pageId));
                continue;
            }

            $result = $this->processPage($page);
            if (!$result['countForBatch']) {
                continue;
            }

            $this->targetedCount++;
            if ($result['delay'] && $this->targetedCount < $batchSize && $this->scanIndex < count($this->pageIds)) {
                $this->sleepForDelay($this->getRateLimitDelay());
            }
            return;
        }

        $this->finishJob();
    }

    /**
     * Processes one page and reports whether it should count toward the batch.
     */
    private function processPage(SiteTree $page): array
    {
        $this->processedCount++;
        $analysis = $page->getBrandVoiceAnalysis();
        $extracted = $this->contentExtractionService()->extractForLiveAnalysis($page);

        if (!$extracted) {
            $this->skippedCount++;
            $this->logger()->info(sprintf(
                'Brand voice job: page %d (%s) skipped - no Live record',
                $page->ID,
                $this->getPageTitle($page)
            ));
            return ['countForBatch' => false, 'delay' => false];
        }

        if ($analysis && !$analysis->isStale($extracted->hash)) {
            $this->skippedCount++;
            $this->logger()->info(sprintf(
                'Brand voice job: page %d (%s) skipped - content unchanged',
                $page->ID,
                $this->getPageTitle($page)
            ));
            return ['countForBatch' => false, 'delay' => false];
        }

        try {
            $this->evaluationService()->evaluateBackgroundWithExtractedContent(
                $page,
                $this->brandVoiceDefinition,
                $extracted,
                $analysis
            );

            if ($extracted->isEmpty()) {
                $this->skippedCount++;
                $this->logger()->info(sprintf(
                    'Brand voice job: page %d (%s) skipped - insufficient content',
                    $page->ID,
                    $this->getPageTitle($page)
                ));
                return ['countForBatch' => true, 'delay' => false];
            }

            $this->succeededCount++;
            $this->logger()->info(sprintf(
                'Brand voice job: page %d (%s) analysed successfully',
                $page->ID,
                $this->getPageTitle($page)
            ));
            return ['countForBatch' => true, 'delay' => true];
        } catch (AIProviderException $exception) {
            $this->failedCount++;
            $this->logger()->error(sprintf(
                'Brand voice job: page %d (%s) failed: %s',
                $page->ID,
                $this->getPageTitle($page),
                $exception->getMessage()
            ));

            if ($exception->isFatal()) {
                $this->addMessage(
                    sprintf('Fatal provider failure for page %d: %s', $page->ID, $exception->getMessage()),
                    'ERROR'
                );
                $this->requeueFreshInstance();
                throw $exception;
            }
            return ['countForBatch' => true, 'delay' => true];
        } catch (Throwable $exception) {
            $this->failedCount++;
            $this->logger()->error(sprintf(
                'Brand voice job: page %d (%s) errored: %s',
                $page->ID,
                $this->getPageTitle($page),
                $exception->getMessage()
            ));
            return ['countForBatch' => true, 'delay' => false];
        }
    }

    /**
     * Marks the current job as complete and queues a fresh follow-up run.
     */
    private function finishJob(): void
    {
        $this->logger()->info(sprintf(
            'Brand voice job completed: processed %d, succeeded %d, failed %d, skipped %d',
            $this->processedCount,
            $this->succeededCount,
            $this->failedCount,
            $this->skippedCount
        ));

        $this->requeueFreshInstance();
        $this->currentStep = $this->totalSteps;
        $this->isComplete = true;
    }

    /**
     * Schedules the next standalone job instance once per run.
     */
    private function requeueFreshInstance(): void
    {
        if ($this->hasRequeued) {
            return;
        }

        $this->hasRequeued = true;
        $delay = $this->getRequeueDelay();
        $startAfter = null;
        if ($delay > 0) {
            $startAfter = DBDatetime::create()
                ->setValue(DBDatetime::now()->getTimestamp() + $delay)
                ->Rfc2822();
        }

        $this->queuedJobService()->queueJob(
            Injector::inst()->create(self::class),
            $startAfter
        );
    }

    /**
     * Loads and normalises the configured brand voice definition from Site Settings.
     */
    private function getConfiguredBrandVoiceDefinition(): string
    {
        $siteConfig = SiteConfig::current_site_config();
        if (!$siteConfig) {
            return '';
        }

        $definition = (string) $siteConfig->BrandVoiceDefinition;
        if ($siteConfig->hasMethod('normaliseBrandVoiceDefinition')) {
            $definition = $siteConfig->normaliseBrandVoiceDefinition($definition);
        }
        return trim($definition);
    }

    /**
     * Resolves the maximum number of pages to analyse in one job run.
     */
    private function getBatchSize(): int
    {
        $batchSize = $this->getEnvInt(self::ENV_BATCH_SIZE, self::DEFAULT_BATCH_SIZE);
        return $batchSize > 0 ? $batchSize : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Resolves the delay applied between provider requests in the same batch.
     */
    private function getRateLimitDelay(): int
    {
        $delay = $this->getEnvInt(self::ENV_RATE_LIMIT_DELAY, self::DEFAULT_RATE_LIMIT_DELAY);
        return $delay > 0 ? $delay : 0;
    }

    /**
     * Resolves how long the next fresh job instance should be delayed.
     */
    private function getRequeueDelay(): int
    {
        $delay = $this->getEnvInt(self::ENV_REQUEUE_DELAY, self::DEFAULT_REQUEUE_DELAY);
        return $delay > 0 ? $delay : 0;
    }

    /**
     * Reads an integer environment variable with a safe fallback.
     */
    private function getEnvInt(string $name, int $default): int
    {
        if (!Environment::hasEnv($name)) {
            return $default;
        }

        $env = Environment::getEnv($name);
        if ($env === null || $env === '' || $env === false) {
            return $default;
        }
        return (int) $env;
    }

    /**
     * Waits between requests, using the injected handler when tests override sleeping.
     */
    private function sleepForDelay(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->sleepHandler) {
            call_user_func($this->sleepHandler, $seconds);
            return;
        }

        usleep($seconds * 1000000);
    }

    /**
     * Resolves a readable page label for logs and queue messages.
     */
    private function getPageTitle(SiteTree $page): string
    {
        $title = trim((string) $page->Title);
        return $title !== '' ? $title : $page->ClassName;
    }

    /**
     * Lazily resolves the evaluation service dependency.
     */
    private function evaluationService(): BrandVoiceEvaluationService
    {
        return $this->evaluationService
            ?: ($this->evaluationService = Injector::inst()->get(BrandVoiceEvaluationService::class));
    }

    /**
     * Lazily resolves the extraction service dependency.
     */
    private function contentExtractionService(): ContentExtractionService
    {
        return $this->contentExtractionService
            ?: ($this->contentExtractionService = Injector::inst()->get(ContentExtractionService::class));
    }

    /**
     * Lazily resolves the logger used by the queued job.
     */
    private function logger(): LoggerInterface
    {
        return $this->logger ?: ($this->logger = Injector::inst()->get(LoggerInterface::class));
    }

    /**
     * Lazily resolves the queue service used to schedule the next run.
     */
    private function queuedJobService(): QueuedJobService
    {
        return $this->queuedJobService
            ?: ($this->queuedJobService = QueuedJobService::singleton());
    }
}
