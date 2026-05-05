<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Elemental-enabled page fixture used by extraction tests.
 */
class CETestElementalPage extends SiteTree implements TestOnly
{
    private static $table_name = 'ABV_CETestElPage';
}
