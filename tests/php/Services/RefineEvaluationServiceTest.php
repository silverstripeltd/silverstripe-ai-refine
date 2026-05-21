<?php

namespace SilverstripeLtd\AiRefine\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverstripeLtd\AiRefine\Services\RefineEvaluationService;
use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverstripeLtd\AiRefine\Tests\StubProvider;
use SilverstripeLtd\AiRefine\Tests\StubProviderFactory;
use SilverstripeLtd\AiRefine\ValueObjects\RefineFullResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises background and draft evaluation orchestration.
 */
class RefineEvaluationServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        RefineAnalysis::class,
    ];

    /**
     * Confirms empty extracted content short-circuits background analysis persistence.
     */
    public function testEvaluateBackgroundMarksInsufficientContent(): void
    {
        $provider = new StubProvider(new RefineFullResult('Excellent', 'Should not be used.', []));
        $service = new RefineEvaluationService(new ContentExtractionService(), new StubProviderFactory($provider));

        $page = SiteTree::create([
            'Title' => ' ',
            'Content' => '',
        ]);
        $page->write();
        $page->publishSingle();

        $analysis = $service->evaluateBackground(
            $page,
            'We write clearly, practically, and with a warm but professional tone.'
        );

        $this->assertSame('Insufficient content', $analysis->GenerationNote);
        $this->assertSame(md5(''), $analysis->ContentHash);
        $this->assertNotEmpty($analysis->AnalysedAt);
        $this->assertNull($analysis->Rating);
        $this->assertNull($analysis->ReasoningSummary);
        $this->assertSame(0, $provider->evaluationCallCount);
    }

    /**
     * Confirms draft-only pages are skipped by background analysis.
     */
    public function testEvaluateBackgroundSkipsDraftOnlyPages(): void
    {
        $provider = new StubProvider();
        $service = new RefineEvaluationService(new ContentExtractionService(), new StubProviderFactory($provider));

        $page = SiteTree::create([
            'Title' => 'Draft only',
            'Content' => '<p>Draft body</p>',
        ]);
        $page->write();

        $analysis = $service->evaluateBackground(
            $page,
            'We write clearly, practically, and with a warm but professional tone.'
        );

        $this->assertNull($analysis);
        $this->assertSame(0, $provider->evaluationCallCount);
    }

    /**
     * Confirms background evaluation persists the provider rating and reasoning.
     */
    public function testEvaluateBackgroundPersistsRatingAndReasoningFromSharedPrompt(): void
    {
        $provider = new StubProvider(
            new RefineFullResult('Excellent', 'Strong alignment.', [])
        );
        $service = new RefineEvaluationService(new ContentExtractionService(), new StubProviderFactory($provider));

        $page = SiteTree::create([
            'Title' => 'Published title',
            'Content' => '<p>Published body</p>',
        ]);
        $page->write();
        $page->publishSingle();

        $analysis = $service->evaluateBackground(
            $page,
            'We write clearly, practically, and with a warm but professional tone.'
        );

        $this->assertSame('Excellent', $analysis->Rating);
        $this->assertSame('Strong alignment.', $analysis->ReasoningSummary);
        $this->assertNull($analysis->GenerationNote);
        $this->assertSame(1, $provider->evaluationCallCount);
    }

    /**
     * Confirms draft evaluation resolves provider suggestions onto local rewrite targets.
     */
    public function testEvaluateDraftReturnsResolvedSuggestions(): void
    {
        $provider = new StubProvider(new RefineFullResult('Good', 'Mostly aligned.', [
            new RefineSuggestion('page:title', 'page_title', '', null, '', 'Updated draft title'),
            new RefineSuggestion('page:content', 'page_content', '', null, '', '<p>Updated draft body</p>'),
        ]));
        $service = new RefineEvaluationService(new ContentExtractionService(), new StubProviderFactory($provider));

        $page = SiteTree::create([
            'Title' => 'Draft title',
            'Content' => '<p>Draft body</p>',
        ]);
        $page->write();

        $result = $service->evaluateDraft(
            $page,
            'We write clearly, practically, and with a warm but professional tone.'
        );

        $this->assertSame('Good', $result->rating);
        $this->assertSame('Mostly aligned.', $result->reasoningSummary);
        $this->assertCount(2, $result->suggestions);
        $this->assertSame('page:title', $result->suggestions[0]->targetKey);
        $this->assertSame('Title', $result->suggestions[0]->fieldName);
        $this->assertSame('Page name', $result->suggestions[0]->fieldLabel);
        $this->assertSame('', $result->suggestions[0]->targetTitle);
        $this->assertSame('Draft title', $result->suggestions[0]->sourceContent);
        $this->assertSame('Updated draft title', $result->suggestions[0]->suggestedContent);
        $this->assertSame('page:content', $result->suggestions[1]->targetKey);
        $this->assertSame('Content', $result->suggestions[1]->fieldName);
        $this->assertSame('Content', $result->suggestions[1]->fieldLabel);
        $this->assertSame('', $result->suggestions[1]->targetTitle);
        $this->assertSame('<p>Draft body</p>', $result->suggestions[1]->sourceContent);
        $this->assertSame('<p>Draft body</p>', $result->suggestions[1]->getDiffSourceContent());
        $this->assertSame('<p>Updated draft body</p>', $result->suggestions[1]->suggestedContent);
        $this->assertSame(1, $provider->evaluationCallCount);
    }

    /**
     * Supplies invalid provider suggestion payloads that should trigger validation errors.
     */
    public static function provideInvalidDraftSuggestions(): array
    {
        return [
            'unexpected target key' => [[
                new RefineSuggestion('page:title', 'page_title', '', null, '', 'Updated draft title'),
                new RefineSuggestion('page:unknown', 'page_content', '', null, '', 'Updated draft body'),
            ], 'AI provider response referenced unexpected target page:unknown'],
            'wrong target type' => [[
                new RefineSuggestion('page:title', 'page_content', '', null, '', 'Updated draft title'),
                new RefineSuggestion('page:content', 'page_content', '', null, '', '<p>Updated draft body</p>'),
            ], 'AI provider response returned the wrong targetType for page:title'],
            'duplicate suggestion' => [[
                new RefineSuggestion('page:title', 'page_title', '', null, '', 'Updated draft title'),
                new RefineSuggestion('page:title', 'page_title', '', null, '', 'Duplicate title'),
            ], 'AI provider response contains duplicate suggestions for target page:title'],
            'missing suggestion' => [[
                new RefineSuggestion('page:title', 'page_title', '', null, '', 'Updated draft title'),
            ], 'AI provider response missing suggestion for target page:content'],
        ];
    }

    /**
     * Confirms invalid provider suggestion payloads are rejected before reaching the UI.
     */
    #[DataProvider('provideInvalidDraftSuggestions')]
    public function testEvaluateDraftRejectsInvalidProviderSuggestions(
        array $suggestions,
        string $expectedMessage
    ): void {
        $provider = new StubProvider(new RefineFullResult('Good', 'Mostly aligned.', $suggestions));
        $service = new RefineEvaluationService(new ContentExtractionService(), new StubProviderFactory($provider));

        $page = SiteTree::create([
            'Title' => 'Draft title',
            'Content' => '<p>Draft body</p>',
        ]);
        $page->write();

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage($expectedMessage);

        $service->evaluateDraft(
            $page,
            'We write clearly, practically, and with a warm but professional tone.'
        );
    }
}
