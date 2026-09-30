<?php

namespace SilverstripeLtd\AiRefine\Controllers;

use DOMElement;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverstripeLtd\AiRefine\Exceptions\RefineApplyException;
use SilverstripeLtd\AiRefine\Extensions\RefineSiteTreeExtension;
use SilverstripeLtd\AiRefine\Forms\RefineCheckForm;
use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverstripeLtd\AiRefine\Services\RefineApplyService;
use SilverstripeLtd\AiRefine\Services\RefineEvaluationService;
use SilverstripeLtd\AiRefine\Services\RefineCheckRateLimiter;
use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverstripeLtd\AiRefine\ValueObjects\RefineApplyResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;
use SilverStripe\Admin\FormSchemaController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\XssSanitiser;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
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
                fn(DataObject $draftRecord): RefineApplyResult => $this->getApplyService()->applyToDraft(
                    $draftRecord,
                    $suggestions
                )
            );
        } catch (RefineApplyException $exception) {
            return $this->jsonResponse(['error' => RefineCheckForm::APPLY_FAILURE_MESSAGE], 403);
        }
        return $this->jsonResponse($result->toArray());
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
     * Returns the apply service that writes selected suggestions to draft content.
     */
    private function getApplyService(): RefineApplyService
    {
        return Injector::inst()->get(RefineApplyService::class);
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
}
