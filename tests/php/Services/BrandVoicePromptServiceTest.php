<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Services;

use SilverstripeLtd\AiBrandVoice\Services\BrandVoicePromptService;
use SilverstripeLtd\AiBrandVoice\Tests\PromptServiceTestExtension;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceRewriteTarget;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests prompt content generation and extension hooks.
 */
class BrandVoicePromptServiceTest extends SapphireTest
{
    /**
     * Confirms the system prompt template is loaded from the module root.
     */
    public function testGetSystemPromptLoadsModuleRootTemplate(): void
    {
        $service = new BrandVoicePromptService();
        $expectedPrompt = trim((string) file_get_contents(dirname(__DIR__, 3) . '/prompts/system.md'));

        $this->assertSame($expectedPrompt, $service->getSystemPrompt());
    }

    /**
     * Clears prompt service extensions after each test.
     */
    protected function tearDown(): void
    {
        Config::modify()->set(BrandVoicePromptService::class, 'extensions', []);

        parent::tearDown();
    }

    /**
     * Confirms the evaluation prompts include the definition, content, and rewrite guidance.
     */
    public function testBuildEvaluationPromptsIncludeDefinitionAndRewriteInstructions(): void
    {
        $service = new BrandVoicePromptService();
        [$systemPrompt, $userPrompt] = $service->buildEvaluationPrompts(
            'Page body',
            'Page Title',
            'Friendly, plain English, and direct.',
            [
                new BrandVoiceRewriteTarget(
                    'page:title',
                    BrandVoiceRewriteTarget::TYPE_PAGE_TITLE,
                    'Title',
                    99,
                    'Page Title'
                ),
                new BrandVoiceRewriteTarget(
                    'page:content',
                    BrandVoiceRewriteTarget::TYPE_PAGE_CONTENT,
                    'Content',
                    99,
                    'Page body'
                ),
                new BrandVoiceRewriteTarget(
                    'element:4:field:myfield',
                    BrandVoiceRewriteTarget::TYPE_ELEMENT_TEXT,
                    'MyField',
                    4,
                    'Block title'
                ),
            ]
        );

        $this->assertStringContainsString('brand voice compliance evaluator', $systemPrompt);
        $this->assertStringContainsString('=== BRAND_VOICE_DEFINITION_START ===', $userPrompt);
        $this->assertStringContainsString('=== PAGE_CONTENT_START ===', $userPrompt);
        $this->assertStringContainsString('=== REWRITE_TARGETS_START ===', $userPrompt);
        $this->assertStringContainsString('Page title: Page Title', $userPrompt);
        $this->assertStringContainsString('"reasoningSummary"', $userPrompt);
        $this->assertStringContainsString('"suggestions"', $userPrompt);
        $this->assertStringContainsString('"targetKey": "page:title"', $userPrompt);
        $this->assertStringContainsString('"contentFormat": "html"', $userPrompt);
        $this->assertStringContainsString('"contentFormat": "text"', $userPrompt);
        $this->assertStringContainsString('Do not add new information', $userPrompt);
        $unchangedSuggestionInstruction = 'If a target already matches the brand voice,'
            . ' return the original sourceContent unchanged as the suggestedContent';
        $rewriteOnlyWhenNeededInstruction = 'Only rewrite content that does not match the brand voice.'
            . ' If the source content already matches, return it unchanged.';

        $this->assertStringContainsString($unchangedSuggestionInstruction, $userPrompt);
        $this->assertStringContainsString($rewriteOnlyWhenNeededInstruction, $userPrompt);
        $this->assertStringContainsString(
            'zero rewrites are needed and every target should be returned unchanged',
            $userPrompt
        );
        $this->assertStringContainsString(
            'return clean HTML suitable for writing directly into the mapped Silverstripe field',
            $userPrompt
        );
        $this->assertStringContainsString(
            'For "text" contentFormat targets, return plain text only with no HTML tags',
            $userPrompt
        );
    }

    /**
     * Confirms extensions can modify the generated prompts.
     */
    public function testExtensionHookCanModifyPrompts(): void
    {
        Config::modify()->merge(BrandVoicePromptService::class, 'extensions', [
            PromptServiceTestExtension::class,
        ]);

        $service = new BrandVoicePromptService();
        [$systemPrompt, $userPrompt] = $service->buildEvaluationPrompts(
            'Page body',
            'Page Title',
            'Friendly, plain English, and direct.'
        );

        $this->assertStringContainsString('Extra system guidance.', $systemPrompt);
        $this->assertStringContainsString('Rewrite extension active.', $userPrompt);
    }
}
