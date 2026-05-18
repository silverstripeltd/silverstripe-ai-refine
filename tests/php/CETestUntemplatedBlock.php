<?php

namespace SilverstripeLtd\AiRefine\Tests;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Dev\TestOnly;

/**
 * Element fixture without a frontend template, used to exercise extraction fallbacks.
 */
class CETestUntemplatedBlock extends BaseElement implements TestOnly
{
    private static $table_name = 'ABV_CETestUBlock';

    private static $db = [
        'MyField' => 'Varchar(255)',
        'MyBigField' => 'Text',
    ];
}
