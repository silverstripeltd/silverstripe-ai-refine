<?php

namespace SilverstripeLtd\AiRefine\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Versioned page fixture used by content extraction tests.
 */
class CETestDraftDiffPage extends SiteTree implements TestOnly
{
    private static $table_name = 'ABV_CETestDraftDiffPage';

    /**
     * Keeps the fixture stable by reporting no draft changes in version comparisons.
     */
    public function isModifiedOnDraft()
    {
        return false;
    }
}
