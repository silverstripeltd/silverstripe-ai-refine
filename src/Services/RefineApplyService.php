<?php

namespace SilverstripeLtd\AiRefine\Services;

use DNADesign\Elemental\Models\BaseElement;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiRefine\Exceptions\RefineApplyException;
use SilverstripeLtd\AiRefine\ValueObjects\RefineApplyResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\XssSanitiser;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\Forms\HTMLEditor\HTMLEditorSanitiser;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\View\Parsers\HTMLValue;

/**
 * Writes selected rewrite suggestions onto a draft record and its owned Elemental blocks.
 */
class RefineApplyService
{
    private ContentExtractionService $contentExtractionService;

    private LoggerInterface $logger;

    /**
     * Builds the apply service with injectable extraction and logging dependencies.
     */
    public function __construct(
        ?ContentExtractionService $contentExtractionService = null,
        ?LoggerInterface $logger = null
    ) {
        $this->contentExtractionService = $contentExtractionService
            ?: Injector::inst()->get(ContentExtractionService::class);
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Applies the selected suggestions to the supplied draft record and reports what was written.
     *
     * The record must already be loaded from the draft stage and pass the caller's edit checks.
     * Each suggestion is an array with the keys targetKey, suggestedContent and one of the
     * apply, rewrite or shouldRewrite flags, optionally with targetType, targetId and fieldName
     * which must match the current rewrite target when present.
     *
     * @param array<int|string, array<string, mixed>> $suggestions
     * @throws RefineApplyException
     */
    public function applyToDraft(DataObject $record, array $suggestions): RefineApplyResult
    {
        $rewriteTargetsByKey = $this->getRewriteTargetsByKey($record);
        $pageElementalAreaIds = $this->getElementalAreaIds($record);
        $resolvedSuggestions = [];
        $seenTargetKeys = [];
        $pageRequiresWrite = false;
        $appliedTargetKeys = [];
        $skippedSuggestions = [];
        foreach ($suggestions as $index => $suggestion) {
            if (!is_array($suggestion)) {
                $skippedSuggestions[] = $this->logApplySkip($record, 'invalid-payload', $index);
                continue;
            }
            if (!$this->shouldApplySuggestion($suggestion)) {
                continue;
            }
            $resolvedSuggestion = $this->resolveApplicableSuggestion(
                $record,
                $suggestion,
                $rewriteTargetsByKey,
                $pageElementalAreaIds,
                $index,
                $seenTargetKeys,
                $skippedSuggestions
            );
            if (!$resolvedSuggestion) {
                continue;
            }
            $resolvedSuggestions[] = [
                'index' => $index,
                'suggestedContent' => $resolvedSuggestion['suggestedContent'],
                'target' => $resolvedSuggestion['target'],
            ];
        }
        $this->assertEditableElementTargets($record, $resolvedSuggestions);
        foreach ($resolvedSuggestions as $resolvedSuggestion) {
            $applied = $this->applyResolvedSuggestion(
                $record,
                $resolvedSuggestion['target'],
                $resolvedSuggestion['suggestedContent'],
                $pageElementalAreaIds,
                $resolvedSuggestion['index'],
                $pageRequiresWrite,
                $skippedSuggestions
            );
            if ($applied) {
                $appliedTargetKeys[] = $resolvedSuggestion['target']->targetKey;
            }
        }
        if ($pageRequiresWrite) {
            $record->write();
        }
        return new RefineApplyResult(
            count($appliedTargetKeys),
            count($skippedSuggestions),
            $appliedTargetKeys,
            $skippedSuggestions
        );
    }

    /**
     * Fails the whole apply when any selected block target cannot be edited.
     */
    private function assertEditableElementTargets(DataObject $record, array $resolvedSuggestions): void
    {
        $checkedElementIds = [];
        foreach ($resolvedSuggestions as $resolvedSuggestion) {
            /** @var RefineRewriteTarget $target */
            $target = $resolvedSuggestion['target'];
            if (!RefineRewriteTarget::isElementTargetType($target->targetType) || !$target->targetId) {
                continue;
            }
            if (isset($checkedElementIds[$target->targetId])) {
                continue;
            }
            $checkedElementIds[$target->targetId] = true;
            $element = BaseElement::get()->setUseCache(false)->byID($target->targetId);
            if ($element && !$element->canEdit()) {
                $this->logger->warning('Refine apply denied by block permissions', [
                    'recordClass' => $record->ClassName,
                    'recordId' => $record->ID,
                    'targetId' => $target->targetId,
                    'targetKey' => $target->targetKey,
                ]);
                throw new RefineApplyException(sprintf(
                    'Block %d cannot be edited by the current user',
                    $target->targetId
                ));
            }
        }
    }

    /**
     * Determines whether an incoming suggestion payload has been marked for apply.
     */
    private function shouldApplySuggestion(array $suggestion): bool
    {
        foreach (['apply', 'rewrite', 'shouldRewrite'] as $flag) {
            if (!array_key_exists($flag, $suggestion)) {
                continue;
            }
            return filter_var($suggestion[$flag], FILTER_VALIDATE_BOOLEAN);
        }
        return false;
    }

    /**
     * Indexes current rewrite targets by their stable target key.
     */
    private function getRewriteTargetsByKey(DataObject $record): array
    {
        $targetsByKey = [];
        foreach ($this->contentExtractionService->extractForDraftCheck($record)->rewriteTargets as $target) {
            $targetsByKey[$target->targetKey] = $target;
        }
        return $targetsByKey;
    }

    /**
     * Validates one selected payload entry and resolves it onto the current rewrite targets.
     */
    private function resolveApplicableSuggestion(
        DataObject $record,
        array $suggestion,
        array $rewriteTargetsByKey,
        array $pageElementalAreaIds,
        int|string $index,
        array &$seenTargetKeys,
        array &$skippedSuggestions
    ): ?array {
        $targetKey = trim((string) ($suggestion['targetKey'] ?? ''));
        if ($targetKey === '') {
            $skippedSuggestions[] = $this->logApplySkip($record, 'missing-target-key', $index);
            return null;
        }
        if (isset($seenTargetKeys[$targetKey])) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'duplicate-target',
                $index,
                ['targetKey' => $targetKey]
            );
            return null;
        }
        $suggestedContent = $suggestion['suggestedContent'] ?? null;
        if (!is_string($suggestedContent)) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'missing-suggested-content',
                $index,
                ['targetKey' => $targetKey]
            );
            return null;
        }
        $target = $rewriteTargetsByKey[$targetKey] ?? null;
        if (!$target) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                $this->resolveMissingTargetReason($suggestion, $pageElementalAreaIds),
                $index,
                ['targetKey' => $targetKey]
            );
            return null;
        }
        if (!$this->suggestionMatchesTarget($suggestion, $target)) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'target-metadata-mismatch',
                $index,
                ['targetKey' => $targetKey]
            );
            return null;
        }
        $seenTargetKeys[$targetKey] = true;
        return [
            'target' => $target,
            'suggestedContent' => $suggestedContent,
        ];
    }

    /**
     * Applies one validated suggestion to either the page record or an owned Elemental block.
     */
    private function applyResolvedSuggestion(
        DataObject $record,
        RefineRewriteTarget $target,
        string $suggestedContent,
        array $pageElementalAreaIds,
        int|string $index,
        bool &$pageRequiresWrite,
        array &$skippedSuggestions
    ): bool {
        if (RefineRewriteTarget::isElementTargetType($target->targetType)) {
            return $this->applyElementSuggestion(
                $record,
                $target,
                $suggestedContent,
                $pageElementalAreaIds,
                $index,
                $skippedSuggestions
            );
        }
        return $this->applyPageSuggestion(
            $record,
            $target,
            $suggestedContent,
            $index,
            $pageRequiresWrite,
            $skippedSuggestions
        );
    }

    /**
     * Verifies that the apply payload still matches the current rewrite target metadata.
     */
    private function suggestionMatchesTarget(array $suggestion, RefineRewriteTarget $target): bool
    {
        $payloadTargetType = $suggestion['targetType'] ?? null;
        if (is_string($payloadTargetType)
            && trim($payloadTargetType) !== ''
            && trim($payloadTargetType) !== $target->targetType) {
            return false;
        }
        $payloadFieldName = $suggestion['fieldName'] ?? null;
        if (is_string($payloadFieldName)
            && trim($payloadFieldName) !== ''
            && trim($payloadFieldName) !== $target->fieldName) {
            return false;
        }
        if (!array_key_exists('targetId', $suggestion)) {
            return true;
        }
        $payloadTargetId = $suggestion['targetId'];
        if ($payloadTargetId === null || $payloadTargetId === '') {
            return $target->targetId === null;
        }
        if (!is_int($payloadTargetId) && !(is_string($payloadTargetId) && ctype_digit($payloadTargetId))) {
            return false;
        }
        return (int) $payloadTargetId === $target->targetId;
    }

    /**
     * Explains why a missing rewrite target should be treated as deleted, foreign, or mismatched.
     */
    private function resolveMissingTargetReason(array $suggestion, array $pageElementalAreaIds): string
    {
        $payloadTargetType = trim((string) ($suggestion['targetType'] ?? ''));
        $payloadTargetId = $suggestion['targetId'] ?? null;
        if (!RefineRewriteTarget::isElementTargetType($payloadTargetType)) {
            return 'mismatched-target';
        }
        if (!is_int($payloadTargetId) && !(is_string($payloadTargetId) && ctype_digit($payloadTargetId))) {
            return 'mismatched-target';
        }
        $element = BaseElement::get()->byID((int) $payloadTargetId);
        if (!$element) {
            return 'deleted-target';
        }
        if (!in_array((int) $element->ParentID, $pageElementalAreaIds, true)) {
            return 'foreign-target';
        }
        return 'mismatched-target';
    }

    /**
     * Sets a page-level suggestion on the draft record and defers the write until the loop finishes.
     */
    private function applyPageSuggestion(
        DataObject $record,
        RefineRewriteTarget $target,
        string $suggestedContent,
        int|string $index,
        bool &$pageRequiresWrite,
        array &$skippedSuggestions
    ): bool {
        if (!$record->hasField($target->fieldName)) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'missing-target-field',
                $index,
                ['targetKey' => $target->targetKey, 'fieldName' => $target->fieldName]
            );
            return false;
        }
        $record->setField(
            $target->fieldName,
            $this->sanitiseSuggestedContent($record, $target->fieldName, $suggestedContent)
        );
        $pageRequiresWrite = true;
        return true;
    }

    /**
     * Writes an element-level suggestion to draft content when the element is still valid.
     */
    private function applyElementSuggestion(
        DataObject $record,
        RefineRewriteTarget $target,
        string $suggestedContent,
        array $pageElementalAreaIds,
        int|string $index,
        array &$skippedSuggestions
    ): bool {
        if (!$target->targetId) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'missing-target-id',
                $index,
                ['targetKey' => $target->targetKey]
            );
            return false;
        }
        $element = BaseElement::get()->byID($target->targetId);
        if (!$element) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'deleted-target',
                $index,
                ['targetKey' => $target->targetKey]
            );
            return false;
        }
        if (!in_array((int) $element->ParentID, $pageElementalAreaIds, true)) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'foreign-target',
                $index,
                ['targetKey' => $target->targetKey]
            );
            return false;
        }
        if (!$element->hasField($target->fieldName)) {
            $skippedSuggestions[] = $this->logApplySkip(
                $record,
                'missing-target-field',
                $index,
                ['targetKey' => $target->targetKey, 'fieldName' => $target->fieldName]
            );
            return false;
        }
        $element->setField(
            $target->fieldName,
            $this->sanitiseSuggestedContent($element, $target->fieldName, $suggestedContent)
        );
        $element->write();
        return true;
    }

    /**
     * Applies the same server-side HTML handling as a CMS save before suggestions are persisted.
     */
    private function sanitiseSuggestedContent(DataObject $record, string $fieldName, string $suggestedContent): string
    {
        $dbField = $record->dbObject($fieldName);
        if ($dbField instanceof DBHTMLText || $dbField instanceof DBHTMLVarchar) {
            $htmlValue = new HTMLValue($suggestedContent);
            HTMLEditorSanitiser::create(HTMLEditorConfig::get_active())->sanitise($htmlValue);
            XssSanitiser::create()->sanitiseHtmlValue($htmlValue);
            return $htmlValue->getContent();
        }
        return strip_tags($suggestedContent);
    }

    /**
     * Collects the Elemental area IDs that belong to the current page record.
     */
    private function getElementalAreaIds(DataObject $record): array
    {
        if (!$record->hasMethod('getElementalRelations')) {
            return [];
        }
        $relations = $record->getElementalRelations();
        if (!is_array($relations)) {
            return [];
        }
        $areaIds = [];
        foreach ($relations as $relation) {
            if (!is_string($relation) || !$record->hasMethod($relation)) {
                continue;
            }
            $area = $record->$relation();
            if ($area && $area->exists()) {
                $areaIds[] = (int) $area->ID;
            }
        }
        return array_values(array_unique($areaIds));
    }

    /**
     * Logs why a payload entry was skipped and returns the entry recorded on the result.
     *
     * @return array{index: int|string, reason: string, targetKey: ?string}
     */
    private function logApplySkip(
        DataObject $record,
        string $reason,
        int|string $index,
        array $context = []
    ): array {
        $this->logger->warning('Refine apply skipped suggestion', array_merge([
            'reason' => $reason,
            'recordClass' => $record->ClassName,
            'recordId' => $record->ID,
            'suggestionIndex' => $index,
        ], $context));
        return [
            'index' => $index,
            'reason' => $reason,
            'targetKey' => $context['targetKey'] ?? null,
        ];
    }
}
