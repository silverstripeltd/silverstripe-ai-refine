<?php

namespace SilverstripeLtd\AiRefine\Tests;

use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverstripeLtd\AiRefine\Providers\AbstractAIProvider;
use SilverstripeLtd\AiRefine\ValueObjects\RefineFullResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;

/**
 * Stub provider returning fixed results.
 */
class StubProvider extends AbstractAIProvider
{
    public int $evaluationCallCount = 0;

    /**
     * Configures the stub to return either a fixed result or a fixed exception.
     */
    public function __construct(
        private readonly ?RefineFullResult $fullResult = null,
        private readonly ?AIProviderException $exception = null
    ) {
    }

    /**
     * Returns the configured result or exception while tracking evaluation calls.
     */
    public function evaluateRefine(
        string $content,
        string $pageTitle,
        string $refineDefinition,
        array $rewriteTargets = []
    ): RefineFullResult {
        $this->evaluationCallCount++;

        if ($this->exception) {
            throw $this->exception;
        }
        return $this->fullResult ?: new RefineFullResult('Good', 'Stub summary', [
            new RefineSuggestion('page:title', 'page_title', '', null, '', 'Updated title'),
        ]);
    }

    /**
     * Returns a dummy raw response for abstract method compatibility.
     */
    protected function performRequest(string $systemPrompt, string $userPrompt, int $maxTokens): array
    {
        return ['status' => 200, 'body' => '{}'];
    }

    /**
     * Returns an empty JSON payload for abstract method compatibility.
     */
    protected function extractResponseContent(string $body): string
    {
        return '{}';
    }

    /**
     * Marks all synthetic stub responses as non-transient.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return false;
    }

    /**
     * Returns the synthetic model name used by the stub provider.
     */
    protected function getDefaultModel(): string
    {
        return 'stub-model';
    }
}
