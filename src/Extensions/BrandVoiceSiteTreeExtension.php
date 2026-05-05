<?php

namespace SilverstripeLtd\AiBrandVoice\Extensions;

use SilverstripeLtd\AiBrandVoice\Models\BrandVoiceAnalysis;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;

/**
 * Adds Brand Voice analysis helpers and CMS toolbar metadata to SiteTree.
 */
class BrandVoiceSiteTreeExtension extends Extension
{
    /**
     * Loads the persisted analysis record for the current page when one exists.
     */
    public function getBrandVoiceAnalysis(): ?BrandVoiceAnalysis
    {
        if (!$this->owner->exists()) {
            return null;
        }
        return BrandVoiceAnalysis::get()
            ->filter([
                'ParentID' => $this->owner->ID,
                'ParentClass' => $this->owner->ClassName,
            ])
            ->first();
    }

    /**
     * Returns an existing analysis record or creates one for the current page.
     */
    public function getOrCreateBrandVoiceAnalysis(): BrandVoiceAnalysis
    {
        $analysis = $this->owner->getBrandVoiceAnalysis();
        if ($analysis && $analysis->exists()) {
            return $analysis;
        }

        $analysis = BrandVoiceAnalysis::create();
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

        if ($fields->dataFieldByName('AiBrandVoiceRecordClass')) {
            return;
        }

        $fields->push(HiddenField::create(
            'AiBrandVoiceRecordClass',
            null,
            $this->owner->ClassName
        ));
    }
}
