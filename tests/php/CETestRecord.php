<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Non-versioned record fixture with custom search content.
 */
class CETestRecord extends DataObject implements TestOnly
{
    private static $table_name = 'ABV_CETestRecord';

    private static $db = [
        'Title' => 'Varchar(255)',
        'Content' => 'HTMLText',
    ];

    /**
     * Supplies Elemental-style search text for extraction tests.
     */
    public function getElementsForSearch(): string
    {
        return 'Elemental content';
    }
}
