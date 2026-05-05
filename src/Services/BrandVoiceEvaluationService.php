<?php

namespace SilverstripeLtd\AiBrandVoice\Services;

use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverstripeLtd\AiBrandVoice\Models\BrandVoiceAnalysis;
use SilverstripeLtd\AiBrandVoice\Providers\ProviderFactory;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceExtractedContent;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceFullResult;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceRewriteTarget;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceSuggestion;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Coordinates extraction, staleness checks, provider calls, and persistence.
 */
class BrandVoiceEvaluationService
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
        string $brandVoiceDefinition,
        ?BrandVoiceAnalysis $analysis = null,
        bool $persist = true
    ): ?BrandVoiceAnalysis {
        $extracted = $this->contentExtractionService->extractForLiveAnalysis($record);
        if (!$extracted) {
            return null;
        }
        return $this->evaluateBackgroundWithExtractedContent(
            $record,
            $brandVoiceDefinition,
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
        string $brandVoiceDefinition,
        BrandVoiceExtractedContent $extracted,
        ?BrandVoiceAnalysis $analysis = null,
        bool $persist = true
    ): BrandVoiceAnalysis {
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
            $result = $this->evaluateExtractedContent($record, $brandVoiceDefinition, $extracted);
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
    public function evaluateDraft(DataObject $record, string $brandVoiceDefinition): ?BrandVoiceFullResult
    {
        $extracted = $this->contentExtractionService->extractForDraftCheck($record);
        if ($extracted->isEmpty()) {
            return null;
        }
        return $this->resolveDraftResult(
            $this->evaluateExtractedContent($record, $brandVoiceDefinition, $extracted),
            $extracted
        );
    }

    /**
     * Checks whether the stored live analysis is missing or older than the current content hash.
     */
    public function isBackgroundAnalysisStale(DataObject $record, ?BrandVoiceAnalysis $analysis = null): bool
    {
        $extracted = $this->contentExtractionService->extractForLiveAnalysis($record);
        if (!$extracted) {
            return false;
        }
        $analysis = $analysis
            ?: ($record->hasMethod('getBrandVoiceAnalysis') ? $record->getBrandVoiceAnalysis() : null);
        return !$analysis || $analysis->isStale($extracted->hash);
    }

    /**
     * Returns the existing analysis record for a page or creates a new one on demand.
     */
    private function resolveAnalysis(DataObject $record): BrandVoiceAnalysis
    {
        if ($record->hasMethod('getOrCreateBrandVoiceAnalysis')) {
            return $record->getOrCreateBrandVoiceAnalysis();
        }
        $analysis = BrandVoiceAnalysis::create();
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
        string $brandVoiceDefinition,
        BrandVoiceExtractedContent $extracted
    ): BrandVoiceFullResult {
        return $this->providerFactory
            ->getProvider()
            ->evaluateBrandVoice(
                $extracted->content,
                $this->getRecordTitle($record),
                $brandVoiceDefinition,
                $extracted->rewriteTargets
            );
    }

    /**
     * Rebuilds the full draft result with suggestion metadata resolved from extracted targets.
     */
    private function resolveDraftResult(
        BrandVoiceFullResult $result,
        BrandVoiceExtractedContent $extracted
    ): BrandVoiceFullResult {
        return new BrandVoiceFullResult(
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
