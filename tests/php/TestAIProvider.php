<?php

namespace SilverstripeLtd\AiRefine\Tests;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverstripeLtd\AiRefine\Providers\AbstractAIProvider;

/**
 * Test provider that returns canned responses.
 */
class TestAIProvider extends AbstractAIProvider
{
    /**
     * Seeds the provider with raw responses for parsing and error tests.
     */
    public function __construct(private array $responses, ?LoggerInterface $logger = null)
    {
        parent::__construct(null, $logger ?: new NullLogger());
    }

    public int $callCount = 0;

    /**
     * Returns the next canned HTTP response for provider parsing tests.
     */
    protected function performRequest(string $systemPrompt, string $userPrompt, int $maxTokens): array
    {
        $response = $this->responses[$this->callCount] ?? ['status' => 200, 'body' => '{}'];
        $this->callCount++;
        return $response;
    }

    /**
     * Returns the raw response body so parsing logic can be tested in isolation.
     */
    protected function extractResponseContent(string $body): string
    {
        return $body;
    }

    /**
     * Marks rate limiting and server failures as transient in tests.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Returns the synthetic default model name used by tests.
     */
    protected function getDefaultModel(): string
    {
        return 'test-model';
    }

    /**
     * Exposes the resolved timeout for assertions.
     */
    public function getResolvedTimeout(): int
    {
        return $this->getTimeout();
    }

    /**
     * Exposes the resolved temperature for assertions.
     */
    public function getResolvedTemperature(): float
    {
        return $this->getTemperature();
    }
}
