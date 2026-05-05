<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Page fixture that always denies access for CMS visibility tests.
 */
class RestrictedBrandVoicePage extends SiteTree implements TestOnly
{
    private static $table_name = 'RestrictedBrandVoicePage';

    /**
     * Prevents the test page from being viewable.
     */
    public function canView($member = null)
    {
        return false;
    }

    /**
     * Prevents the test page from being editable.
     */
    public function canEdit($member = null)
    {
        return false;
    }
}
