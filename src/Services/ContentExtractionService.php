<?php

namespace SilverstripeLtd\AiRefine\Services;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementContent;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiRefine\ValueObjects\RefineExtractedContent;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
 use SilverStripe\Core\Convert;
 use SilverStripe\Core\Extensible;
 use SilverStripe\Core\Injector\Injector;
 use SilverStripe\ORM\DataObject;
 use SilverStripe\Versioned\Versioned;
 use SilverStripe\View\Exception\MissingTemplateException;

/**
 * Extracts the exact text payload that is sent to providers.
 */
class ContentExtractionService
{
    use Extensible;

    public const READ_MODE_DRAFT = 'draft';
    public const READ_MODE_LIVE = 'live';

    /**
     * Extracts the current draft payload and rewrite targets for an editor check.
     */
    public function extractForDraftCheck(DataObject $record): RefineExtractedContent
    {
        $resolvedRecord = $this->resolveRecordForMode($record, self::READ_MODE_DRAFT) ?: $record;
        return $this->buildExtractedContent($resolvedRecord, self::READ_MODE_DRAFT, true);
    }

    /**
     * Extracts the published payload for background analysis, or null when no Live record exists.
     */
    public function extractForLiveAnalysis(DataObject $record): ?RefineExtractedContent
    {
        $resolvedRecord = $this->resolveRecordForMode($record, self::READ_MODE_LIVE);
        if (!$resolvedRecord) {
            return null;
        }
        return $this->buildExtractedContent($resolvedRecord, self::READ_MODE_LIVE, false);
    }

    /**
     * Computes the stable hash used to detect whether extracted content has changed.
     */
    public function computeHash(string $content): string
    {
        return md5($content);
    }

    /**
     * Builds the extracted text payload, content hash, and rewrite targets for a record.
     */
    private function buildExtractedContent(
        DataObject $record,
        string $mode,
        bool $filterInteractiveElementTargets
    ): RefineExtractedContent {
        $parts = [];
        $title = $record->hasField('Title') ? trim((string) $record->Title) : '';
        if ($title !== '') {
            $parts[] = $title;
        }
        $content = $this->buildPrimaryBodyContent($record);
        if ($content !== '') {
            $parts[] = $content;
        }
        $extracted = trim(implode("\n\n", $parts));
        $this->extend('updateExtractedContent', $extracted, $record, $mode);
        $extracted = trim($extracted);
        $rewriteTargets = $this->buildRewriteTargets($record, $filterInteractiveElementTargets);
        $this->extend('updateExtractedRewriteTargets', $rewriteTargets, $record, $mode);
        return new RefineExtractedContent(
            $extracted,
            $this->computeHash($extracted),
            $mode,
            $rewriteTargets
        );
    }

    /**
     * Builds the main content body from Elemental search content or the page Content field.
     */
    private function buildPrimaryBodyContent(DataObject $record): string
    {
        $content = '';
        if ($record->hasMethod('getElementsForSearch')) {
            $content = $this->buildElementalSearchContent($record);
        }
        if ($content === '' && $record->hasField('Content')) {
            $content = trim(Convert::html2raw((string) $record->Content));
        }
        return $content;
    }

    /**
     * Extracts search text from Elemental content, with a template-free fallback when needed.
     */
    private function buildElementalSearchContent(DataObject $record): string
    {
        try {
            return trim((string) $record->getElementsForSearch());
        } catch (MissingTemplateException $exception) {
            $this->getLogger()->warning('Refine extraction fell back to Elemental CMS search content', [
                'recordClass' => $record->ClassName,
                'recordID' => $record->exists() ? (int) $record->ID : null,
                'exceptionClass' => $exception::class,
            ]);
        }
        if (!$record->hasMethod('getContentFromElementsForCmsSearch')) {
            return '';
        }
        $content = str_replace(['|%|', '|#|'], ' ', (string) $record->getContentFromElementsForCmsSearch());
        $normalised = preg_replace('/\s+/u', ' ', trim($content));
        return $normalised !== null ? trim($normalised) : trim($content);
    }

    /**
     * Builds the safe rewrite targets that AI suggestions may update for a record.
     */
    private function buildRewriteTargets(DataObject $record, bool $filterInteractiveElementTargets): array
    {
        $targets = [];
        $recordId = $record->exists() ? (int) $record->ID : null;
        $title = $record->hasField('Title') ? trim((string) $record->Title) : '';

        if ($title !== '') {
            $targets[] = $this->createRewriteTarget(
                $record,
                'page:title',
                RefineRewriteTarget::TYPE_PAGE_TITLE,
                'Title',
                $recordId,
                $title
            );
        }

        $elementTargets = $this->buildElementRewriteTargets($record, $filterInteractiveElementTargets);
        if ($elementTargets !== []) {
            return array_merge($targets, $elementTargets);
        }

        if ($record->hasField('Content')) {
            $rawHtml = trim((string) $record->Content);
            if ($rawHtml !== '') {
                $targets[] = $this->createRewriteTarget(
                    $record,
                    'page:content',
                    RefineRewriteTarget::TYPE_PAGE_CONTENT,
                    'Content',
                    $recordId,
                    $rawHtml
                );
            }
        }
        return $targets;
    }

    /**
     * Builds rewrite targets for supported Elemental blocks on the current record.
     */
    private function buildElementRewriteTargets(DataObject $record, bool $filterInteractiveElementTargets): array
    {
        if (!$record->hasMethod('getElementalRelations')) {
            return [];
        }

        $relations = $record->getElementalRelations();
        if (!is_array($relations)) {
            return [];
        }

        $targets = [];

        foreach ($relations as $relation) {
            if (!is_string($relation) || !$record->hasMethod($relation)) {
                continue;
            }

            $area = $record->$relation();
            if (!$area || !$area->exists()) {
                continue;
            }

            foreach ($area->Elements() as $element) {
                if (!$element instanceof BaseElement) {
                    continue;
                }
                if ($filterInteractiveElementTargets && !$element->canView()) {
                    continue;
                }

                $targets = array_merge($targets, $this->buildElementFieldTargets($element));
            }
        }
        return $targets;
    }

    /**
     * Builds rewrite targets for each supported editable field on one Elemental block.
     */
    private function buildElementFieldTargets(BaseElement $element): array
    {
        $targets = [];

        foreach ($this->getSupportedElementFieldTypes($element) as $fieldName => $targetType) {
            $rawContent = (string) $element->getField($fieldName);
            $content = $this->normaliseElementFieldContent($rawContent, $targetType);
            if ($content === '') {
                continue;
            }

            $targets[] = $this->createRewriteTarget(
                $element,
                $this->buildElementTargetKey($element, $fieldName, $targetType),
                $targetType,
                $fieldName,
                (int) $element->ID,
                $content
            );
        }
        return $targets;
    }

    /**
     * Creates one rewrite target with CMS labels and diff metadata attached.
     */
    private function createRewriteTarget(
        DataObject $record,
        string $targetKey,
        string $targetType,
        string $fieldName,
        ?int $targetId,
        string $sourceContent,
        string $diffSourceContent = ''
    ): RefineRewriteTarget {
        return new RefineRewriteTarget(
            $targetKey,
            $targetType,
            $fieldName,
            $targetId,
            $sourceContent,
            $this->resolveFieldLabel($record, $fieldName),
            $this->resolveTargetTitle($record),
            $diffSourceContent
        );
    }

    /**
     * Detects which Elemental database fields can be rewritten and how they should be treated.
     */
    private function getSupportedElementFieldTypes(BaseElement $element): array
    {
        $supported = [];
        $excludedFields = $this->getExcludedElementFieldNames($element);

        foreach (DataObject::getSchema()->databaseFields($element) as $fieldName => $databaseFieldType) {
            if (in_array($fieldName, $excludedFields, true)) {
                continue;
            }

            $targetType = $this->resolveElementTargetType((string) $databaseFieldType);
            if ($targetType === null) {
                continue;
            }

            $supported[$fieldName] = $targetType;
        }
        return $supported;
    }

    /**
     * Returns Elemental fields that should never become rewrite targets.
     */
    private function getExcludedElementFieldNames(BaseElement $element): array
    {
        return array_values(array_unique([
            ...array_filter(
                array_keys((array) BaseElement::config()->get('db')),
                static fn(string $fieldName): bool => $fieldName !== 'Title'
            ),
            ...array_keys((array) $element->config()->get('fixed_fields')),
            ...(array) $element->config()->get('fields_excluded_from_cms_search'),
        ]));
    }

    /**
     * Maps a database field type to the matching rewrite target type, if supported.
     */
    private function resolveElementTargetType(string $databaseFieldType): ?string
    {
        $lcType = strtolower(strtok($databaseFieldType, '(') ?: $databaseFieldType);

        if (str_contains($lcType, 'html')) {
            return RefineRewriteTarget::TYPE_ELEMENT_HTML;
        }

        if (str_contains($lcType, 'varchar') || str_contains($lcType, 'text')) {
            return RefineRewriteTarget::TYPE_ELEMENT_TEXT;
        }
        return null;
    }

    /**
     * Normalises Elemental field content into the plain text source used for prompts.
     */
    private function normaliseElementFieldContent(string $content, string $targetType): string
    {
        if ($targetType === RefineRewriteTarget::TYPE_ELEMENT_HTML) {
            return trim($content);
        }
        return $this->normaliseRewriteSourceContent($content);
    }

    /**
     * Builds the stable target key used to match suggestions back to an Elemental field.
     */
    private function buildElementTargetKey(BaseElement $element, string $fieldName, string $targetType): string
    {
        if ($targetType === RefineRewriteTarget::TYPE_ELEMENT_HTML
            && $element instanceof ElementContent
            && $fieldName === 'HTML') {
            return sprintf('element:%d:html', $element->ID);
        }
        return sprintf('element:%d:field:%s', $element->ID, strtolower($fieldName));
    }

    /**
     * Resolves the CMS field label used in modal metadata and apply validation.
     */
    private function resolveFieldLabel(DataObject $record, string $fieldName): string
    {
        $label = trim((string) $record->fieldLabel($fieldName));
        return $label !== '' ? $label : $fieldName;
    }

    /**
     * Resolves a readable title for element rewrite targets.
     */
    private function resolveTargetTitle(DataObject $record): string
    {
        if (!$record instanceof BaseElement) {
            return '';
        }

        $title = $record->hasField('Title') ? trim((string) $record->getField('Title')) : '';
        if ($title !== '') {
            return $title;
        }

        if ($record->hasMethod('getType')) {
            $type = trim((string) $record->getType());
            if ($type !== '' && strtolower($type) !== 'block') {
                return $type;
            }
        }
        return '';
    }

    /**
     * Normalises source content whitespace before hashing and prompting.
     */
    private function normaliseRewriteSourceContent(string $content): string
    {
        $normalised = preg_replace('/\s+/u', ' ', trim($content));
        return $normalised !== null ? trim($normalised) : trim($content);
    }

    /**
     * Resolves the correct draft or live record instance for the requested read mode.
     */
    private function resolveRecordForMode(DataObject $record, string $mode): ?DataObject
    {
        if (!$record->hasExtension(Versioned::class)) {
            return $record;
        }

        if (!$record->exists()) {
            return $mode === self::READ_MODE_DRAFT ? $record : null;
        }
        return Versioned::withVersionedMode(function () use ($record, $mode): ?DataObject {
            if ($mode === self::READ_MODE_DRAFT) {
                $draftRecord = $this->readVersionedRecord($record, Versioned::DRAFT);
                if ($draftRecord) {
                    return $draftRecord;
                }
                return $this->readVersionedRecord($record, Versioned::LIVE);
            }
            return $this->readVersionedRecord($record, Versioned::LIVE);
        });
    }

    /**
     * Reads one versioned record from the requested stage after resetting Elemental caches.
     */
    private function readVersionedRecord(DataObject $record, string $stage): ?DataObject
    {
        Versioned::set_stage($stage);
        $this->resetElementalCache();
        return DataObject::get($record->ClassName)->byID($record->ID);
    }

    /**
     * Clears the cached Elemental area map so stage switches return fresh data.
     */
    private function resetElementalCache(): void
    {
        $hasElementalCache = class_exists(ElementalPageExtension::class)
            && property_exists(ElementalPageExtension::class, 'elementalAreas');

        if (!$hasElementalCache) {
            return;
        }

        $property = new \ReflectionProperty(ElementalPageExtension::class, 'elementalAreas');
        if (!$property->isStatic()) {
            return;
        }

        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    /**
     * Resolves the logger used for extraction fallbacks and warnings.
     */
    private function getLogger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }
}
