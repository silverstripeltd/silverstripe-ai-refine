<?php

namespace SilverstripeLtd\AiRefine\Controllers;

use DOMElement;
use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\Forms\HTMLEditor\HTMLEditorSanitiser;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverstripeLtd\AiRefine\Extensions\RefineSiteTreeExtension;
use SilverstripeLtd\AiRefine\Forms\RefineCheckForm;
use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverstripeLtd\AiRefine\Services\RefineEvaluationService;
use SilverstripeLtd\AiRefine\Services\RefineCheckRateLimiter;
use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;
use SilverStripe\Admin\FormSchemaController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\XssSanitiser;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\HtmlDiff;
use SilverStripe\View\Parsers\HTMLValue;

/**
 * Serves schema and check responses for the CMS Refine modal.
 */
class RefineController extends FormSchemaController
{
    private const ALLOWED_DIFF_HTML_ELEMENTS = [
        'del',
        'ins',
        'p',
    ];
    private const STALE_SECURITY_TOKEN_MESSAGE = 'Session timed out, please refresh and try again.';

    private static $url_segment = 'ai-refine';

    private static $menu_title = 'Refine';

    private static $menu_priority = -1;

    private static $url_handlers = [
        'GET schema/$ID' => 'schema',
        'POST check/$ID' => 'check',
        'POST apply/$ID' => 'apply',
    ];

    private static $allowed_actions = [
        'schema',
        'check',
        'apply',
    ];

    /**
     * Returns the client-side endpoint and modal config consumed by the CMS boot code.
     */
    public function getClientConfig(): array
    {
        $config = parent::getClientConfig();
        $className = 'ai-refine-modal';
        $modalSelector = '.' . implode('.', preg_split('/\s+/', trim($className)));
        $config['form']['refineCheck'] = [
            'schemaUrl' => $this->Link('schema'),
            'checkUrl' => $this->Link('check'),
            'applyUrl' => $this->Link('apply'),
            'className' => $className,
            'modalClassName' => $className,
            'modalSelector' => $modalSelector,
            'size' => 'xl',
        ];
        return $config;
    }

    /**
     * Returns the modal schema payload and Refine metadata for a record.
     */
    public function schema(HTTPRequest $request): HTTPResponse
    {
        $record = $this->resolveRecordFromRequest($request);
        if ($record instanceof HTTPResponse) {
            return $record;
        }
        $refineConfigured = $this->hasRefineDefinition();
        $form = RefineCheckForm::createForRecord($this, $record, $refineConfigured);
        return $this->getSchemaResponse(
            $request->getURL(),
            $form,
            null,
            ['meta' => $this->buildSchemaMeta($record, $form, $refineConfigured)]
        );
    }

    /**
     * Evaluates the saved draft content and returns a serialised Refine result.
     */
    public function check(HTTPRequest $request): HTTPResponse
    {
        $tokenResponse = $this->requireValidSecurityToken($request);
        if ($tokenResponse) {
            return $tokenResponse;
        }
        $record = $this->resolveRecordFromRequest($request);
        if ($record instanceof HTTPResponse) {
            return $record;
        }
        $refineDefinition = $this->getRefineDefinition();
        if ($refineDefinition === '') {
            return $this->jsonResponse([
                'error' => $this->getEmptyRefineMessage(),
            ], 400);
        }
        $retryAfter = $this->getCheckRateLimiter()->consumeRequest(
            $request->getSession(),
            $this->getCurrentMemberId(),
            (int) $record->ID
        );
        if ($retryAfter > 0) {
            return $this->buildRateLimitedCheckResponse($retryAfter);
        }
        try {
            $result = $this->getEvaluationService()->evaluateDraft($record, $refineDefinition);
        } catch (AIProviderException $exception) {
            $this->logProviderException($exception, $record);
            return $this->jsonResponse([
                'error' => $this->getProviderErrorMessage($exception),
            ], 500);
        }
        if (!$result) {
            return $this->jsonResponse([
                'error' => RefineCheckForm::NO_CONTENT_MESSAGE,
            ], 400);
        }
        $suggestions = $result->rating === 'Excellent'
            ? []
            : array_map(
                fn(RefineSuggestion $suggestion): array => $this->serialiseSuggestion($suggestion),
                $result->suggestions
            );
        return $this->jsonResponse([
            'rating' => $result->rating,
            'ratingLabel' => RefineAnalysis::getRatingLabel($result->rating, $result->rating),
            'reasoningSummary' => $result->reasoningSummary,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Applies the selected rewrite suggestions back onto the saved draft record.
     */
    public function apply(HTTPRequest $request): HTTPResponse
    {
        $tokenResponse = $this->requireValidSecurityToken($request);
        if ($tokenResponse) {
            return $tokenResponse;
        }
        $record = $this->resolveRecordFromRequest($request);
        if ($record instanceof HTTPResponse) {
            return $record;
        }
        $suggestions = $this->resolveApplySuggestionsFromRequest($request);
        if ($suggestions instanceof HTTPResponse) {
            return $suggestions;
        }
        try {
            $result = $this->withDraftStage(
                $record,
                fn(DataObject $draftRecord): array => $this->applySuggestionsToDraft(
                    $draftRecord,
                    $suggestions
                )
            );
        } catch (HTTPResponse_Exception $exception) {
            return $exception->getResponse();
        }
        return $this->jsonResponse([
            'appliedCount' => $result['appliedCount'],
            'skippedCount' => $result['skippedCount'],
            'reloadRequired' => $result['appliedCount'] > 0,
        ]);
    }

    /**
     * Builds the extra modal metadata that the React UI reads from the schema response.
     */
    private function buildSchemaMeta(DataObject $record, Form $form, bool $refineConfigured): array
    {
        $draftExtraction = $this->getContentExtractionService()->extractForDraftCheck($record);
        $formFields = [
            'draftNotice' => 'RefineDraftNotice',
            'emptyState' => 'RefineEmptyState',
            'rating' => 'RatingDisplay',
            'reasoning' => 'ReasoningSummaryDisplay',
            'rewrite' => 'RewrittenContentDisplay',
            'copyAffordance' => 'RefineCopyAffordance',
        ];
        return [
            'refine' => [
                'title' => RefineCheckForm::MODAL_TITLE,
                'record' => [
                    'id' => $record->ID,
                    'fqcn' => $record->ClassName,
                ],
                'messages' => [
                    'draftNotice' => RefineCheckForm::DRAFT_NOTICE,
                    'emptyState' => RefineCheckForm::EMPTY_STATE_MESSAGE,
                    'missingRefine' => $this->getEmptyRefineMessage(),
                    'allAligned' => RefineCheckForm::ALL_ALIGNED_MESSAGE,
                    'noContent' => RefineCheckForm::NO_CONTENT_MESSAGE,
                    'checkSuccess' => RefineCheckForm::CHECK_SUCCESS_MESSAGE,
                    'checkFailure' => RefineCheckForm::CHECK_FAILURE_MESSAGE,
                    'applySuccess' => RefineCheckForm::APPLY_SUCCESS_MESSAGE,
                    'applyPartial' => RefineCheckForm::APPLY_PARTIAL_MESSAGE,
                    'applyFailure' => RefineCheckForm::APPLY_FAILURE_MESSAGE,
                    'copySuccess' => RefineCheckForm::COPY_SUCCESS_MESSAGE,
                    'copyFailure' => RefineCheckForm::COPY_FAILURE_MESSAGE,
                ],
                'labels' => [
                    'check' => RefineCheckForm::CHECK_BUTTON_LABEL,
                    'recheck' => RefineCheckForm::RECHECK_BUTTON_LABEL,
                    'apply' => RefineCheckForm::APPLY_BUTTON_LABEL,
                    'copy' => RefineCheckForm::COPY_BUTTON_LABEL,
                    'applySuggestion' => RefineCheckForm::APPLY_SUGGESTION_LABEL,
                    'rating' => RefineCheckForm::RATING_LABEL,
                    'reasoning' => RefineCheckForm::REASONING_LABEL,
                    'rewrite' => RefineCheckForm::REWRITE_LABEL,
                ],
                'ratingLabels' => RefineAnalysis::getRatingLabels(),
                'fields' => [
                    'rating' => [
                        'name' => $formFields['rating'],
                        'label' => RefineCheckForm::RATING_LABEL,
                    ],
                    'reasoning' => [
                        'name' => $formFields['reasoning'],
                        'label' => RefineCheckForm::REASONING_LABEL,
                        'rows' => RefineCheckForm::REASONING_ROWS,
                        'readOnly' => true,
                    ],
                    'rewrite' => [
                        'name' => $formFields['rewrite'],
                        'label' => RefineCheckForm::REWRITE_LABEL,
                        'rows' => RefineCheckForm::REWRITE_ROWS,
                        'readOnly' => true,
                        'copyable' => true,
                    ],
                ],
                'form' => [
                    'name' => $form->getName(),
                    'action' => $form->FormAction(),
                    'fields' => $formFields,
                ],
                'actions' => [
                    'checkUrl' => $this->Link(sprintf(
                        'check/%d?fqcn=%s',
                        $record->ID,
                        rawurlencode($record->ClassName)
                    )),
                    'applyUrl' => $this->Link(sprintf(
                        'apply/%d?fqcn=%s',
                        $record->ID,
                        rawurlencode($record->ClassName)
                    )),
                ],
                'errors' => [
                    'provider' => [
                        'mode' => $this->shouldExposeProviderErrors() ? 'development' : 'generic',
                        'genericMessage' => RefineCheckForm::PROVIDER_ERROR_MESSAGE,
                    ],
                ],
                'state' => [
                    'refineConfigured' => $refineConfigured,
                    'contentHash' => $draftExtraction->hash,
                    'contentMode' => 'draft',
                    'supportsApply' => true,
                    'storesResultsServerSide' => false,
                ],
            ],
        ];
    }

    /**
     * Serialises one suggestion and hardens the diff HTML before it reaches the CMS.
     */
    private function serialiseSuggestion(RefineSuggestion $suggestion): array
    {
        $payload = $suggestion->toArray();
        $payload['diffHtml'] = $this->buildSuggestionDiffHtml($suggestion);
        return $payload;
    }

    /**
     * Builds the HtmlDiff preview and strips it down to safe presentation markup.
     */
    private function buildSuggestionDiffHtml(RefineSuggestion $suggestion): string
    {
        $sourceContent = $this->flattenToParagraphs($suggestion->getDiffSourceContent());
        return $this->sanitiseDiffHtml(
            HtmlDiff::compareHtml(
                $sourceContent,
                $suggestion->suggestedContent,
                $suggestion->contentFormat !== 'html'
            )
        );
    }

    /**
     * Reduces HTML to plain paragraphs so the diff library receives no markup it could pass through.
     */
    private function flattenToParagraphs(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $htmlValue = new HTMLValue($html);
        foreach ($this->getHtmlBodyElements($htmlValue) as $element) {
            $tag = strtolower($element->tagName);

            if ($tag === 'p') {
                $this->stripElementAttributes($element);
                continue;
            }

            $this->unwrapElement($element);
        }
        return $htmlValue->getContent();
    }

    /**
     * Sanitises diff HTML with Silverstripe's XSS filter and a conservative element allowlist.
     */
    private function sanitiseDiffHtml(string $diffHtml): string
    {
        if (trim($diffHtml) === '') {
            return '';
        }

        $htmlValue = new HTMLValue($diffHtml);
        XssSanitiser::create()
            ->setKeepInnerHtmlOnRemoveElement(false)
            ->sanitiseHtmlValue($htmlValue);
        $this->stripDisallowedDiffElements($htmlValue);
        $this->stripDiffElementAttributes($htmlValue);
        return $htmlValue->getContent();
    }

    /**
     * Removes any tags that the modal diff preview does not need to render.
     */
    private function stripDisallowedDiffElements(HTMLValue $htmlValue): void
    {
        foreach ($this->getHtmlBodyElements($htmlValue) as $element) {
            if (in_array(strtolower($element->tagName), self::ALLOWED_DIFF_HTML_ELEMENTS, true)) {
                continue;
            }

            $this->unwrapElement($element);
        }
    }

    /**
     * Removes all remaining attributes because the diff preview only needs element structure.
     */
    private function stripDiffElementAttributes(HTMLValue $htmlValue): void
    {
        foreach ($this->getHtmlBodyElements($htmlValue) as $element) {
            $this->stripElementAttributes($element);
        }
    }

    /**
     * Collects body elements into a stable array before the DOM is mutated.
     */
    private function getHtmlBodyElements(HTMLValue $htmlValue): array
    {
        $elements = [];
        foreach ($htmlValue->query('//body//*') as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }
        return $elements;
    }

    /**
     * Removes all attributes because the modal diff preview only needs element structure.
     */
    private function stripElementAttributes(DOMElement $element): void
    {
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    /**
     * Removes one element but keeps its child content in the diff preview flow.
     */
    private function unwrapElement(DOMElement $element): void
    {
        $parentNode = $element->parentNode;
        if (!$parentNode) {
            return;
        }

        while ($element->firstChild) {
            $parentNode->insertBefore($element->firstChild, $element);
        }

        $parentNode->removeChild($element);
    }

    /**
     * Normalises the incoming apply payload from either JSON or form-encoded requests.
     */
    private function resolveApplySuggestionsFromRequest(HTTPRequest $request): array|HTTPResponse
    {
        $body = trim((string) $request->getBody());
        $payload = $body !== '' ? json_decode($body, true) : null;
        if (!is_array($payload)) {
            $payload = $request->postVars();
        }

        $suggestions = $payload['suggestions'] ?? null;
        if (!is_array($suggestions)) {
            return $this->jsonResponse(['error' => 'Invalid apply request payload'], 400);
        }
        return $suggestions;
    }

    /**
     * Applies the selected suggestions to the current draft record and its owned elements.
     */
    private function applySuggestionsToDraft(DataObject $record, array $suggestions): array
    {
        $rewriteTargetsByKey = $this->getRewriteTargetsByKey($record);
        $pageElementalAreaIds = $this->getElementalAreaIds($record);
        $resolvedSuggestions = [];
        $seenTargetKeys = [];
        $pageRequiresWrite = false;
        $appliedCount = 0;
        $skippedCount = 0;

        foreach ($suggestions as $index => $suggestion) {
            if (!is_array($suggestion)) {
                $this->logApplySkip($record, 'invalid-payload', $index);
                $skippedCount++;
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
                $seenTargetKeys
            );
            if (!$resolvedSuggestion) {
                $skippedCount++;
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
            if (!$this->applyResolvedSuggestion(
                $record,
                $resolvedSuggestion['target'],
                $resolvedSuggestion['suggestedContent'],
                $pageElementalAreaIds,
                $resolvedSuggestion['index'],
                $pageRequiresWrite
            )) {
                $skippedCount++;
                continue;
            }

            $appliedCount++;
        }

        if ($pageRequiresWrite) {
            $record->write();
        }
        return [
            'appliedCount' => $appliedCount,
            'skippedCount' => $skippedCount,
        ];
    }

    /**
     * Fails the whole apply request when any selected block target cannot be edited.
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
                $this->getLogger()->warning('Refine apply denied by block permissions', [
                    'recordClass' => $record->ClassName,
                    'recordId' => $record->ID,
                    'targetId' => $target->targetId,
                    'targetKey' => $target->targetKey,
                ]);
                $this->failRequest(403, RefineCheckForm::APPLY_FAILURE_MESSAGE);
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
        foreach ($this->getContentExtractionService()->extractForDraftCheck($record)->rewriteTargets as $target) {
            $targetsByKey[$target->targetKey] = $target;
        }
        return $targetsByKey;
    }

    /**
     * Validates one selected apply payload entry and resolves it onto the current rewrite targets.
     */
    private function resolveApplicableSuggestion(
        DataObject $record,
        array $suggestion,
        array $rewriteTargetsByKey,
        array $pageElementalAreaIds,
        int|string $index,
        array &$seenTargetKeys
    ): ?array {
        $targetKey = trim((string) ($suggestion['targetKey'] ?? ''));
        if ($targetKey === '') {
            $this->logApplySkip($record, 'missing-target-key', $index);
            return null;
        }

        if (isset($seenTargetKeys[$targetKey])) {
            $this->logApplySkip($record, 'duplicate-target', $index, ['targetKey' => $targetKey]);
            return null;
        }

        $suggestedContent = $suggestion['suggestedContent'] ?? null;
        if (!is_string($suggestedContent)) {
            $this->logApplySkip($record, 'missing-suggested-content', $index, ['targetKey' => $targetKey]);
            return null;
        }

        $target = $rewriteTargetsByKey[$targetKey] ?? null;
        if (!$target) {
            $this->logApplySkip(
                $record,
                $this->resolveMissingTargetReason($suggestion, $pageElementalAreaIds),
                $index,
                ['targetKey' => $targetKey]
            );
            return null;
        }

        if (!$this->suggestionMatchesTarget($suggestion, $target)) {
            $this->logApplySkip($record, 'target-metadata-mismatch', $index, ['targetKey' => $targetKey]);
            return null;
        }

        $seenTargetKeys[$targetKey] = true;
        return [
            'target' => $target,
            'suggestedContent' => $suggestedContent,
        ];
    }

    /**
     * Applies one validated suggestion to either the page record or an owned elemental block.
     */
    private function applyResolvedSuggestion(
        DataObject $record,
        RefineRewriteTarget $target,
        string $suggestedContent,
        array $pageElementalAreaIds,
        int|string $index,
        bool &$pageRequiresWrite
    ): bool {
        if (RefineRewriteTarget::isElementTargetType($target->targetType)) {
            return $this->applyElementSuggestion(
                $record,
                $target,
                $suggestedContent,
                $pageElementalAreaIds,
                $index
            );
        }
        return $this->applyPageSuggestion($record, $target, $suggestedContent, $index, $pageRequiresWrite);
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
     * Writes a page-level suggestion to the draft record and defers the final write until the loop finishes.
     */
    private function applyPageSuggestion(
        DataObject $record,
        RefineRewriteTarget $target,
        string $suggestedContent,
        int|string $index,
        bool &$pageRequiresWrite
    ): bool {
        if (!$record->hasField($target->fieldName)) {
            $this->logApplySkip(
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
        int|string $index
    ): bool {
        if (!$target->targetId) {
            $this->logApplySkip($record, 'missing-target-id', $index, ['targetKey' => $target->targetKey]);
            return false;
        }

        $element = BaseElement::get()->byID($target->targetId);
        if (!$element) {
            $this->logApplySkip($record, 'deleted-target', $index, ['targetKey' => $target->targetKey]);
            return false;
        }

        if (!in_array((int) $element->ParentID, $pageElementalAreaIds, true)) {
            $this->logApplySkip($record, 'foreign-target', $index, ['targetKey' => $target->targetKey]);
            return false;
        }

        if (!$element->hasField($target->fieldName)) {
            $this->logApplySkip(
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
     * Collects the elemental area IDs that belong to the current page record.
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
     * Records why an apply payload entry was skipped for later debugging.
     */
    private function logApplySkip(
        DataObject $record,
        string $reason,
        int|string $index,
        array $context = []
    ): void {
        $this->getLogger()->warning('Refine apply skipped suggestion', array_merge([
            'reason' => $reason,
            'recordClass' => $record->ClassName,
            'recordId' => $record->ID,
            'suggestionIndex' => $index,
        ], $context));
    }

    /**
     * Resolves the current page record from the request and checks edit access.
     */
    private function resolveRecordFromRequest(HTTPRequest $request): DataObject|HTTPResponse
    {
        $fqcn = urldecode((string) ($request->getVar('fqcn') ?: $request->param('FQCN')));
        $id = (int) ($request->param('ID') ?: $request->param('ItemID'));

        if ($fqcn === '' || $id <= 0) {
            return $this->jsonResponse(['error' => 'Invalid request parameters'], 400);
        }

        if (!class_exists($fqcn)
            || !is_a($fqcn, SiteTree::class, true)
            || !DataObject::has_extension($fqcn, RefineSiteTreeExtension::class)) {
            return $this->jsonResponse(['error' => 'Invalid record class'], 400);
        }

        $record = DataObject::get($fqcn)->byID($id);
        if (!$record) {
            return $this->jsonResponse(['error' => 'Record not found'], 404);
        }

        if (!$record->canEdit()) {
            return $this->jsonResponse(['error' => 'Access denied'], 403);
        }
        return $record;
    }

    /**
     * Rejects stale or missing SecurityID values before any write or provider call runs.
     */
    private function requireValidSecurityToken(HTTPRequest $request): ?HTTPResponse
    {
        if (SecurityToken::inst()->checkRequest($request)) {
            return null;
        }
        return $this->jsonResponse([
            'error' => self::STALE_SECURITY_TOKEN_MESSAGE,
        ], 403);
    }

    private function buildRateLimitedCheckResponse(int $retryAfter): HTTPResponse
    {
        $response = $this->jsonResponse([
            'error' => $this->getRateLimitErrorMessage($retryAfter),
        ], 429);
        $response->addHeader('Retry-After', (string) $retryAfter);
        return $response;
    }

    private function getCurrentMemberId(): int
    {
        return (int) (Security::getCurrentUser()?->ID ?? 0);
    }

    private function getRateLimitErrorMessage(int $retryAfter): string
    {
        return sprintf(
            'Too many AI refine requests for this page. Please wait %s and try again.',
            $this->formatCooldownDuration($retryAfter)
        );
    }

    private function formatCooldownDuration(int $retryAfter): string
    {
        if ($retryAfter >= 60) {
            $minutes = (int) ceil($retryAfter / 60);
            return sprintf('%d %s', $minutes, $minutes === 1 ? 'minute' : 'minutes');
        }
        return sprintf('%d %s', $retryAfter, $retryAfter === 1 ? 'second' : 'seconds');
    }

    private function getCheckRateLimiter(): RefineCheckRateLimiter
    {
        return Injector::inst()->get(RefineCheckRateLimiter::class);
    }

    /**
     * Returns the evaluation service used for draft and background checks.
     */
    private function getEvaluationService(): RefineEvaluationService
    {
        return Injector::inst()->get(RefineEvaluationService::class);
    }

    /**
     * Returns the extraction service used to rebuild rewrite targets for apply.
     */
    private function getContentExtractionService(): ContentExtractionService
    {
        return Injector::inst()->get(ContentExtractionService::class);
    }

    /**
     * Runs a callback against the draft stage version of a versioned record.
     */
    private function withDraftStage(DataObject $record, callable $callback): mixed
    {
        if (!$record->hasExtension(Versioned::class)) {
            return $callback($record);
        }
        return Versioned::withVersionedMode(function () use ($record, $callback): mixed {
            Versioned::set_stage(Versioned::DRAFT);

            $draftRecord = DataObject::get($record->ClassName)->byID($record->ID) ?: $record;
            return $callback($draftRecord);
        });
    }

    /**
     * Reads and normalises the configured site-wide Refine definition.
     */
    private function getRefineDefinition(): string
    {
        $siteConfig = SiteConfig::current_site_config();
        $definition = $siteConfig ? (string) $siteConfig->RefineDefinition : '';

        if ($siteConfig && $siteConfig->hasMethod('normaliseRefineDefinition')) {
            return (string) $siteConfig->normaliseRefineDefinition($definition);
        }
        return trim($definition);
    }

    /**
     * Checks whether the site currently has any Refine definition configured.
     */
    private function hasRefineDefinition(): bool
    {
        return $this->getRefineDefinition() !== '';
    }

    /**
     * Returns the empty-state message shown when no Refine has been configured.
     */
    private function getEmptyRefineMessage(): string
    {
        $siteConfig = SiteConfig::current_site_config();

        if ($siteConfig && $siteConfig->hasMethod('getRefineEmptyStateMessage')) {
            return (string) $siteConfig->getRefineEmptyStateMessage();
        }
        return 'No refine has been defined. Configure your refine in Settings > Refine.';
    }

    /**
     * Chooses the provider error message that is safe to expose to the current environment.
     */
    private function getProviderErrorMessage(AIProviderException $exception): string
    {
        if ($this->shouldExposeProviderErrors()) {
            return $exception->getMessage();
        }
        return RefineCheckForm::PROVIDER_ERROR_MESSAGE;
    }

    /**
     * Limits raw provider errors to development requests outside the PHPUnit runtime.
     */
    private function shouldExposeProviderErrors(): bool
    {
        $runningTests = defined('PHPUNIT_COMPOSER_INSTALL');
        return Director::isDev() && !$runningTests;
    }

    /**
     * Logs the original provider exception with record context for debugging.
     */
    private function logProviderException(AIProviderException $exception, DataObject $record): void
    {
        $this->getLogger()->error('Refine provider request failed', [
            'exception' => $exception,
            'recordClass' => $record->ClassName,
            'recordId' => $record->ID,
        ]);
    }

    /**
     * Returns the module logger used for provider and apply diagnostics.
     */
    private function getLogger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Builds the JSON response used by the modal schema, check, and apply endpoints.
     */
    private function jsonResponse(array $body, int $code = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($body), $code)
            ->addHeader('Content-Type', 'application/json');
    }

    /**
     * Throws a JSON HTTP error response.
     */
    private function failRequest(int $statusCode, string $message): never
    {
        throw new HTTPResponse_Exception($this->jsonResponse(['error' => $message], $statusCode));
    }
}
