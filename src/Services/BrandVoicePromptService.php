<?php

namespace SilverstripeLtd\AiBrandVoice\Services;

use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceRewriteTarget;
use SilverStripe\Core\Extensible;

/**
 * Builds the provider prompts for shared evaluation flows.
 */
class BrandVoicePromptService
{
    use Extensible;

    /**
     * Builds the shared system and user prompts used for provider evaluation calls.
     */
    public function buildEvaluationPrompts(
        string $content,
        string $pageTitle,
        string $brandVoiceDefinition,
        array $rewriteTargets = []
    ): array {
        return $this->buildPrompts(
            $this->getSystemPrompt(),
            $this->renderPromptTemplate(
                'rewrite-user.md',
                $content,
                $pageTitle,
                $brandVoiceDefinition,
                $rewriteTargets
            ),
            true,
            $content,
            $pageTitle,
            $brandVoiceDefinition
        );
    }

    /**
     * Loads the base system prompt template from the module prompts directory.
     */
    public function getSystemPrompt(): string
    {
        return trim((string) file_get_contents($this->getPromptsDirectory() . '/system.md'));
    }

    /**
     * Applies extension hooks and returns the final system and user prompt pair.
     */
    private function buildPrompts(
        string $systemPrompt,
        string $userPrompt,
        bool $includeRewrite,
        string $content,
        string $pageTitle,
        string $brandVoiceDefinition
    ): array {
        $this->extend(
            'updateBrandVoicePrompts',
            $systemPrompt,
            $userPrompt,
            $includeRewrite,
            $content,
            $pageTitle,
            $brandVoiceDefinition
        );
        return [$systemPrompt, $userPrompt];
    }

    /**
     * Renders one prompt template with page content and rewrite-target placeholders.
     */
    private function renderPromptTemplate(
        string $template,
        string $content,
        string $pageTitle,
        string $brandVoiceDefinition,
        array $rewriteTargets = []
    ): string {
        $rawTemplate = (string) file_get_contents($this->getPromptsDirectory() . '/' . $template);
        return trim(str_replace(
            ['{brandVoiceDefinition}', '{pageTitle}', '{content}', '{rewriteTargets}'],
            [
                trim($brandVoiceDefinition),
                trim($pageTitle),
                trim($content),
                $this->serialiseRewriteTargets($rewriteTargets),
            ],
            $rawTemplate
        ));
    }

    /**
     * Serialises rewrite targets into the JSON payload embedded in the prompt.
     */
    private function serialiseRewriteTargets(array $rewriteTargets): string
    {
        return (string) json_encode(
            array_map(
                static fn(BrandVoiceRewriteTarget $target): array => $target->toPromptPayload(),
                $rewriteTargets
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Returns the module directory that contains the prompt markdown templates.
     */
    private function getPromptsDirectory(): string
    {
        return dirname(__DIR__, 2) . '/prompts';
    }
}
