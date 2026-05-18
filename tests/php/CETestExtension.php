<?php

namespace SilverstripeLtd\AiRefine\Tests;

use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Test extension that appends extracted content and rewrite targets.
 */
class CETestExtension extends Extension
{
    /**
     * Appends extra extracted content during live reads.
     */
    public function updateExtractedContent(string &$content, DataObject $record, string $mode): void
    {
        if ($mode === 'live') {
            $content .= "\n\nAppended from extension";
        }
    }

    /**
     * Adds an extra rewrite target during draft reads.
     */
    public function updateExtractedRewriteTargets(array &$targets, DataObject $record, string $mode): void
    {
        if ($mode !== ContentExtractionService::READ_MODE_DRAFT) {
            return;
        }

        $targets[] = new RefineRewriteTarget(
            'extension:summary',
            RefineRewriteTarget::TYPE_PAGE_CONTENT,
            'Content',
            $record->exists() ? (int) $record->ID : null,
            'Extension supplied summary',
            'Extension summary',
            'Extension provided target'
        );
    }
}
