<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use DNADesign\Elemental\Models\ElementContent;
use SilverStripe\Dev\TestOnly;

/**
 * Element fixture that cannot be mutated through interactive apply.
 */
class CETestLockedElement extends ElementContent implements TestOnly
{
    private static $table_name = 'ABV_CETestLockedEl';

    public function canEdit($member = null): bool
    {
        return false;
    }
}
