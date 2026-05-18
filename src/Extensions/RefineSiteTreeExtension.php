<?php

namespace SilverstripeLtd\AiRefine\Extensions;

use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;

/**
 * Adds Refine analysis helpers and CMS toolbar metadata to SiteTree.
 */
class RefineSiteTreeExtension extends Extension
{
    /**
     * Loads the persisted analysis record for the current page when one exists.
     */
    public function getRefineAnalysis(): ?RefineAnalysis
    {
        if (!$this->owner->exists()) {
            return null;
        }
        return RefineAnalysis::get()
            ->filter([
                'ParentID' => $this->owner->ID,
                'ParentClass' => $this->owner->ClassName,
            ])
            ->first();
    }

    /**
     * Returns an existing analysis record or creates one for the current page.
     */
    public function getOrCreateRefineAnalysis(): RefineAnalysis
    {
        $analysis = $this->owner->getRefineAnalysis();
        if ($analysis && $analysis->exists()) {
            return $analysis;
        }

        $analysis = RefineAnalysis::create();
        if ($this->owner->exists()) {
            $analysis->ParentID = $this->owner->ID;
            $analysis->ParentClass = $this->owner->ClassName;
            $analysis->write();
        }
        return $analysis;
    }

    /**
     * Adds hidden toolbar context so the CMS modal can resolve the current record.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        if (!$this->owner->exists() || !$this->owner->canEdit()) {
            return;
        }

        if ($fields->dataFieldByName('AiRefineRecordClass')) {
            return;
        }

        $fields->push(HiddenField::create(
            'AiRefineRecordClass',
            null,
            $this->owner->ClassName
        ));
    }
}
