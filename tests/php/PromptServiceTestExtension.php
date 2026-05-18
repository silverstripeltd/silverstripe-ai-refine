<?php

namespace SilverstripeLtd\AiRefine\Tests;

use SilverStripe\Core\Extension;

/**
 * Extends prompts during tests.
 */
class PromptServiceTestExtension extends Extension
{
    /**
     * Appends extra prompt text so the extension hook can be asserted in tests.
     */
    public function updateRefinePrompts(
        string &$systemPrompt,
        string &$userPrompt,
        bool $includeRewrite
    ): void {
        $systemPrompt .= ' Extra system guidance.';
        $userPrompt .= $includeRewrite ? "\nRewrite extension active." : "\nRating extension active.";
    }
}
