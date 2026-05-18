<?php

namespace SilverstripeLtd\AiRefine\Tests\Jobs;

use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverstripeLtd\AiRefine\Jobs\EvaluateRefineJob;
use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverstripeLtd\AiRefine\Services\RefineEvaluationService;
use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverstripeLtd\AiRefine\Tests\SequenceStubProvider;
use SilverstripeLtd\AiRefine\Tests\StubProviderFactory;
use SilverstripeLtd\AiRefine\Tests\TestLogger;
use SilverstripeLtd\AiRefine\Tests\TestQueuedJobService;
use SilverstripeLtd\AiRefine\ValueObjects\RefineFullResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Covers queued refine evaluation behaviour.
 */
class EvaluateRefineJobTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        RefineAnalysis::class,
        SiteConfig::class,
    ];

    /**
     * Sets deterministic queue timing defaults before each job test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Environment::setEnv('AI_REFINE_RATE_LIMIT_DELAY', '0');
        Environment::setEnv('AI_REFINE_JOB_REQUEUE_DELAY', '60');
    }

    /**
     * Clears environment overrides and SiteConfig state after each test.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_REFINE_JOB_BATCH_SIZE', null);
        Environment::setEnv('AI_REFINE_RATE_LIMIT_DELAY', null);
        Environment::setEnv('AI_REFINE_JOB_REQUEUE_DELAY', null);

        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms the job finishes immediately when no refine is configured.
     */
    public function testJobSkipsAllPagesWhenNoRefineConfigured(): void
    {
        $queue = new TestQueuedJobService();
        $job = $this->createJob(new SequenceStubProvider(), $queue, new TestLogger());

        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertSame(0, $job->processedCount);
        $this->assertSame(1, count($job->getJobData()->messages));
        $this->assertSame(1, count($queue->queuedJobs));
    }

    /**
     * Confirms skipped pages do not consume the batch before a stale page is found.
     */
    public function testJobScansPastSkippedPagesToProcessNextTarget(): void
    {
        Environment::setEnv('AI_REFINE_JOB_BATCH_SIZE', '1');
        $this->setRefineDefinition();

        $draftOnly = SiteTree::create([
            'Title' => 'Draft only',
            'Content' => '<p>Draft content</p>',
        ]);
        $draftOnly->write();

        $upToDate = SiteTree::create([
            'Title' => 'Up to date',
            'Content' => '<p>Published content</p>',
        ]);
        $upToDate->write();
        $upToDate->publishSingle();
        $currentHash = md5("Up to date\n\nPublished content");
        $upToDateAnalysis = $upToDate->getOrCreateRefineAnalysis();
        $upToDateAnalysis->Rating = 'Good';
        $upToDateAnalysis->ReasoningSummary = 'Already analysed';
        $upToDateAnalysis->ContentHash = $currentHash;
        $upToDateAnalysis->AnalysedAt = '2026-04-22 10:00:00';
        $upToDateAnalysis->write();

        $stale = SiteTree::create([
            'Title' => 'Needs review',
            'Content' => '<p>Fresh content</p>',
        ]);
        $stale->write();
        $stale->publishSingle();

        $provider = new SequenceStubProvider([
            new RefineFullResult('Poor', 'Needs work', []),
        ]);
        $queue = new TestQueuedJobService();
        $job = $this->createJob($provider, $queue, new TestLogger());

        $this->runJob($job);

        $this->assertTrue($job->jobFinished());
        $this->assertSame(3, $job->processedCount);
        $this->assertSame(1, $job->succeededCount);
        $this->assertSame(2, $job->skippedCount);
        $this->assertSame(1, $provider->evaluationCallCount);
        $this->assertSame(1, count($queue->queuedJobs));
        $this->assertSame('Poor', $stale->getRefineAnalysis()->Rating);
        $this->assertSame($currentHash, $upToDate->getRefineAnalysis()->ContentHash);
    }

    /**
     * Confirms insufficient content is marked analysed until the published content changes.
     */
    public function testJobMarksInsufficientContentAsAnalysedUntilContentChanges(): void
    {
        Environment::setEnv('AI_REFINE_JOB_BATCH_SIZE', '1');
        $this->setRefineDefinition();

        $page = SiteTree::create([
            'Title' => '   ',
            'Content' => '',
        ]);
        $page->write();
        $page->publishSingle();

        $provider = new SequenceStubProvider([
            new RefineFullResult('Excellent', 'Now substantial enough', []),
        ]);
        $queue = new TestQueuedJobService();

        $firstJob = $this->createJob($provider, $queue, new TestLogger());
        $this->runJob($firstJob);

        $analysis = $page->getRefineAnalysis();
        $firstAnalysedAt = $analysis->AnalysedAt;
        $this->assertSame('Insufficient content', $analysis->GenerationNote);
        $this->assertSame(md5(''), $analysis->ContentHash);
        $this->assertNull($analysis->Rating);
        $this->assertSame(0, $provider->evaluationCallCount);

        $secondJob = $this->createJob($provider, new TestQueuedJobService(), new TestLogger());
        $this->runJob($secondJob);

        $analysis = $page->getRefineAnalysis();
        $this->assertSame($firstAnalysedAt, $analysis->AnalysedAt);
        $this->assertSame(0, $provider->evaluationCallCount);

        $page->Title = 'Now substantial';
        $page->Content = '<p>Enough published content to evaluate now.</p>';
        $page->write();
        $page->publishSingle();

        $thirdJob = $this->createJob($provider, new TestQueuedJobService(), new TestLogger());
        $this->runJob($thirdJob);

        $analysis = $page->getRefineAnalysis();
        $this->assertSame('Excellent', $analysis->Rating);
        $this->assertNull($analysis->GenerationNote);
        $this->assertSame(1, $provider->evaluationCallCount);
    }

    /**
     * Confirms non-fatal provider errors do not stop the rest of the batch.
     */
    public function testJobContinuesAfterNonFatalProviderException(): void
    {
        Environment::setEnv('AI_REFINE_JOB_BATCH_SIZE', '2');
        $this->setRefineDefinition();

        $firstPage = $this->createPublishedPage('First page', '<p>First body</p>');
        $secondPage = $this->createPublishedPage('Second page', '<p>Second body</p>');

        $provider = new SequenceStubProvider([
            new AIProviderException('Temporary upstream issue', false),
            new RefineFullResult('Good', 'Recovered', []),
        ]);
        $queue = new TestQueuedJobService();
        $job = $this->createJob($provider, $queue, new TestLogger());

        $this->runJob($job);

        $this->assertTrue($job->jobFinished());
        $this->assertSame(2, $provider->evaluationCallCount);
        $this->assertSame(1, $job->failedCount);
        $this->assertSame(1, $job->succeededCount);
        $firstAnalysis = $firstPage->getRefineAnalysis();
        $this->assertNull($firstAnalysis ? $firstAnalysis->AnalysedAt : null);
        $this->assertSame('Good', $secondPage->getRefineAnalysis()->Rating);
        $this->assertSame(1, count($queue->queuedJobs));
    }

    /**
     * Confirms fatal provider errors stop the run and queue a fresh job instance.
     */
    public function testJobStopsAndRequeuesOnFatalProviderException(): void
    {
        Environment::setEnv('AI_REFINE_JOB_BATCH_SIZE', '2');
        $this->setRefineDefinition();

        $firstPage = $this->createPublishedPage('Fatal page', '<p>Fatal body</p>');
        $secondPage = $this->createPublishedPage('Unreached page', '<p>Second body</p>');

        $provider = new SequenceStubProvider([
            new AIProviderException('Invalid API key', true),
            new RefineFullResult('Good', 'Should not run', []),
        ]);
        $queue = new TestQueuedJobService();
        $job = $this->createJob($provider, $queue, new TestLogger());

        $this->expectException(AIProviderException::class);
        try {
            $job->process();
        } finally {
            $this->assertFalse($job->jobFinished());
            $this->assertSame(1, $provider->evaluationCallCount);
            $this->assertSame(1, $job->failedCount);
            $this->assertSame(1, count($queue->queuedJobs));
            $firstAnalysis = $firstPage->getRefineAnalysis();
            $this->assertNull($firstAnalysis ? $firstAnalysis->AnalysedAt : null);
            $this->assertNull($secondPage->getRefineAnalysis());
        }
    }

    /**
     * Creates a job with stubbed services and a no-op sleep handler.
     */
    private function createJob(
        SequenceStubProvider $provider,
        TestQueuedJobService $queue,
        TestLogger $logger
    ): EvaluateRefineJob {
        $job = new EvaluateRefineJob();
        $job->setEvaluationService(new RefineEvaluationService(
            new ContentExtractionService(),
            new StubProviderFactory($provider)
        ));
        $job->setContentExtractionService(new ContentExtractionService());
        $job->setQueuedJobService($queue);
        $job->setLogger($logger);
        $job->setSleepHandler(static function (): void {
        });
        return $job;
    }

    /**
     * Creates and publishes a SiteTree page fixture for queued job tests.
     */
    private function createPublishedPage(string $title, string $content): SiteTree
    {
        $page = SiteTree::create([
            'Title' => $title,
            'Content' => $content,
        ]);
        $page->write();
        $page->publishSingle();
        return $page;
    }

    /**
     * Writes a valid refine definition into Site Settings.
     */
    private function setRefineDefinition(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = 'We write clearly, practically, and with a calm, helpful tone '
            . 'that makes complex information easy to understand.';
        $siteConfig->write();
    }

    /**
     * Repeatedly processes the job until it reports completion.
     */
    private function runJob(EvaluateRefineJob $job): void
    {
        $iterations = 0;
        while (!$job->jobFinished()) {
            $job->process();
            $iterations++;
            $this->assertLessThan(20, $iterations);
        }
    }
}
