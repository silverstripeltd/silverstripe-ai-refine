<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverstripeLtd\AiBrandVoice\Providers\AbstractAIProvider;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceFullResult;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceSuggestion;

/**
 * Provider stub that returns a queued sequence of results or exceptions.
 */
class SequenceStubProvider extends AbstractAIProvider
{
    public int $evaluationCallCount = 0;

    /**
     * @var list<BrandVoiceFullResult|AIProviderException>
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
    public function evaluateBrandVoice(
        string $content,
        string $pageTitle,
        string $brandVoiceDefinition,
        array $rewriteTargets = []
    ): BrandVoiceFullResult {
        $this->evaluationCallCount++;
        $response = array_shift($this->responses);

        if ($response instanceof AIProviderException) {
            throw $response;
        }
        return $response instanceof BrandVoiceFullResult
            ? $response
            : new BrandVoiceFullResult('Good', 'Stub summary', [
                new BrandVoiceSuggestion('page:title', 'page_title', '', null, '', 'Updated title'),
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
