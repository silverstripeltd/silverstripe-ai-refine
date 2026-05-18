<?php

namespace SilverstripeLtd\AiRefine\Tests;

use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverstripeLtd\AiRefine\Providers\AbstractAIProvider;
use SilverstripeLtd\AiRefine\ValueObjects\RefineFullResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;

/**
 * Provider stub that returns a queued sequence of results or exceptions.
 */
class SequenceStubProvider extends AbstractAIProvider
{
    public int $evaluationCallCount = 0;

    /**
     * @var list<RefineFullResult|AIProviderException>
     */
    private array $responses;

    /**
     * Seeds the stub with a sequence of provider outcomes.
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    /**
     * Returns the next queued response and increments the evaluation counter.
     */
    public function evaluateRefine(
        string $content,
        string $pageTitle,
        string $refineDefinition,
        array $rewriteTargets = []
    ): RefineFullResult {
        $this->evaluationCallCount++;
        $response = array_shift($this->responses);

        if ($response instanceof AIProviderException) {
            throw $response;
        }
        return $response instanceof RefineFullResult
            ? $response
            : new RefineFullResult('Good', 'Stub summary', [
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
     * Marks all synthetic sequence responses as non-transient.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return false;
    }

    /**
     * Returns the synthetic model name used by the sequence stub.
     */
    protected function getDefaultModel(): string
    {
        return 'sequence-stub-model';
    }
}
