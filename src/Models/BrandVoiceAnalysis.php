<?php

namespace SilverstripeLtd\AiBrandVoice\Models;

use SilverStripe\ORM\DataObject;

/**
 * Stores the latest persisted brand voice analysis for a record.
 */
class BrandVoiceAnalysis extends DataObject
{
    private const RATING_LABELS = [
        'Excellent' => 'Excellent',
        'Good' => 'Good',
        'Adequate' => 'Adequate',
        'NeedsWork' => 'Needs work',
        'Poor' => 'Poor',
    ];

    private static $table_name = 'BrandVoiceAnalysis';

    private static $db = [
        'Rating' => "Enum('Excellent,Good,Adequate,NeedsWork,Poor')",
        'ReasoningSummary' => 'Text',
        'ContentHash' => 'Varchar(32)',
        'AnalysedAt' => 'Datetime',
        'GenerationNote' => 'Varchar(255)',
    ];

    private static $has_one = [
        'Parent' => DataObject::class,
    ];

    /**
     * Returns the CMS label used for each stored rating value.
     */
    public static function getRatingLabels(): array
    {
        return self::RATING_LABELS;
    }

    /**
     * Resolves one stored rating value to its human-readable label.
     */
    public static function getRatingLabel(?string $rating, string $fallback = 'Not analysed'): string
    {
        $rating = trim((string) $rating);
        return self::RATING_LABELS[$rating] ?? $fallback;
    }

    /**
     * Reports whether the stored analysis is missing or no longer matches the content hash.
     */
    public function isStale(string $currentHash): bool
    {
        if (!$this->exists() || !$this->AnalysedAt) {
            return true;
        }
        return (string) $this->ContentHash !== $currentHash;
    }
}
