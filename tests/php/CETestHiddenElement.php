<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use DNADesign\Elemental\Models\ElementContent;
use SilverStripe\Dev\TestOnly;

/**
 * Element fixture hidden from interactive suggestion targets.
 */
class CETestHiddenElement extends ElementContent implements TestOnly
{
    private static $table_name = 'ABV_CETestHiddenEl';

    public function canView($member = null): bool
    {
        return false;
    }
}
