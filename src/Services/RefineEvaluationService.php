<?php

namespace SilverstripeLtd\AiRefine\Services;

use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverstripeLtd\AiRefine\Providers\ProviderFactory;
use SilverstripeLtd\AiRefine\ValueObjects\RefineExtractedContent;
use SilverstripeLtd\AiRefine\ValueObjects\RefineFullResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Coordinates extraction, staleness checks, provider calls, and persistence.
 */
class RefineEvaluationService
{
    private ContentExtractionService $contentExtractionService;

    private ProviderFactory $providerFactory;

    /**
     * Builds the evaluation service with injectable extraction and provider dependencies.
     */
    public function __construct(
        ?ContentExtractionService $contentExtractionService = null,
        ?ProviderFactory $providerFactory = null
    ) {
        $this->contentExtractionService = $contentExtractionService
            ?: Injector::inst()->get(ContentExtractionService::class);
        $this->providerFactory = $providerFactory ?: Injector::inst()->get(ProviderFactory::class);
    }

    /**
     * Runs or reuses the persisted live analysis for a published record.
     */
    public function evaluateBackground(
        DataObject $record,
        string $refineDefinition,
        ?RefineAnalysis $analysis = null,
        bool $persist = true
    ): ?RefineAnalysis {
        $extracted = $this->contentExtractionService->extractForLiveAnalysis($record);
        if (!$extracted) {
            return null;
        }
        return $this->evaluateBackgroundWithExtractedContent(
            $record,
            $refineDefinition,
            $extracted,
            $analysis,
            $persist
        );
    }

    /**
     * Evaluates extracted live content and persists the refreshed analysis when requested.
     */
    public function evaluateBackgroundWithExtractedContent(
        DataObject $record,
        string $refineDefinition,
        RefineExtractedContent $extracted,
        ?RefineAnalysis $analysis = null,
        bool $persist = true
    ): RefineAnalysis {
        $analysis = $analysis ?: $this->resolveAnalysis($record);
        if (!$analysis->isStale($extracted->hash)) {
            return $analysis;
        }
        $analysis->ContentHash = $extracted->hash;
        $analysis->AnalysedAt = DBDatetime::now()->getValue();
        if ($extracted->isEmpty()) {
            $analysis->Rating = null;
            $analysis->ReasoningSummary = null;
            $analysis->GenerationNote = 'Insufficient content';
        } else {
            $result = $this->evaluateExtractedContent($record, $refineDefinition, $extracted);
            $analysis->Rating = $result->rating;
            $analysis->ReasoningSummary = $result->reasoningSummary;
            $analysis->GenerationNote = null;
        }
        if ($persist) {
            $analysis->write();
        }
        return $analysis;
    }

    /**
     * Evaluates the saved draft content and resolves provider suggestions onto local targets.
     */
    public function evaluateDraft(DataObject $record, string $refineDefinition): ?RefineFullResult
    {
        $extracted = $this->contentExtractionService->extractForDraftCheck($record);
        if ($extracted->isEmpty()) {
            return null;
        }
        return $this->resolveDraftResult(
            $this->evaluateExtractedContent($record, $refineDefinition, $extracted),
            $extracted
        );
    }

    /**
     * Checks whether the stored live analysis is missing or older than the current content hash.
     */
    public function isBackgroundAnalysisStale(DataObject $record, ?RefineAnalysis $analysis = null): bool
    {
        $extracted = $this->contentExtractionService->extractForLiveAnalysis($record);
        if (!$extracted) {
            return false;
        }
        $analysis = $analysis
            ?: ($record->hasMethod('getRefineAnalysis') ? $record->getRefineAnalysis() : null);
        return !$analysis || $analysis->isStale($extracted->hash);
    }

    /**
     * Returns the existing analysis record for a page or creates a new one on demand.
     */
    private function resolveAnalysis(DataObject $record): RefineAnalysis
    {
        if ($record->hasMethod('getOrCreateRefineAnalysis')) {
            return $record->getOrCreateRefineAnalysis();
        }
        $analysis = RefineAnalysis::create();
        if ($record->exists()) {
            $analysis->ParentID = $record->ID;
            $analysis->ParentClass = $record->ClassName;
        }
        return $analysis;
    }

    /**
     * Resolves a human-readable title for prompt and logging context.
     */
    private function getRecordTitle(DataObject $record): string
    {
        $title = $record->hasField('Title') ? trim((string) $record->Title) : '';
        return $title !== '' ? $title : $record->ClassName;
    }

    /**
     * Sends one extracted payload to the configured provider with shared prompt context.
     */
    private function evaluateExtractedContent(
        DataObject $record,
        string $refineDefinition,
        RefineExtractedContent $extracted
    ): RefineFullResult {
        return $this->providerFactory
            ->getProvider()
            ->evaluateRefine(
                $extracted->content,
                $this->getRecordTitle($record),
                $refineDefinition,
                $extracted->rewriteTargets
            );
    }

    /**
     * Rebuilds the full draft result with suggestion metadata resolved from extracted targets.
     */
    private function resolveDraftResult(
        RefineFullResult $result,
        RefineExtractedContent $extracted
    ): RefineFullResult {
        return new RefineFullResult(
            $result->rating,
            $result->reasoningSummary,
            $this->resolveSuggestions($result->suggestions, $extracted->rewriteTargets)
        );
    }

    /**
     * Validates provider suggestions against extracted targets and fills in local metadata.
     *
     * @throws AIProviderException
     */
    private function resolveSuggestions(array $suggestions, array $rewriteTargets): array
    {
        $targetsByKey = [];
        foreach ($rewriteTargets as $target) {
            $targetsByKey[$target->targetKey] = $target;
        }
        $resolved = [];
        foreach ($suggestions as $suggestion) {
            $target = $targetsByKey[$suggestion->targetKey] ?? null;
            if (!$target) {
                throw new AIProviderException(sprintf(
                    'AI provider response referenced unexpected target %s',
                    $suggestion->targetKey
                ));
            }
            if ($suggestion->targetType !== $target->targetType) {
                throw new AIProviderException(sprintf(
                    'AI provider response returned the wrong targetType for %s',
                    $suggestion->targetKey
                ));
            }
            if (isset($resolved[$suggestion->targetKey])) {
                throw new AIProviderException(sprintf(
                    'AI provider response contains duplicate suggestions for target %s',
                    $suggestion->targetKey
                ));
            }
            $resolved[$suggestion->targetKey] = $suggestion->withResolvedTarget($target);
        }
        foreach ($rewriteTargets as $target) {
            if (!isset($resolved[$target->targetKey])) {
                throw new AIProviderException(sprintf(
                    'AI provider response missing suggestion for target %s',
                    $target->targetKey
                ));
            }
        }
        return array_values($resolved);
    }
}
