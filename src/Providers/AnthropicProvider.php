<?php

namespace SilverstripeLtd\AiBrandVoice\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;

/**
 * Provider integration for Anthropic Messages API.
 */
class AnthropicProvider extends AbstractAIProvider
{
    /**
     * Sends one evaluation request to Anthropic's Messages API.
     */
    protected function performRequest(string $systemPrompt, string $userPrompt, int $maxTokens): array
    {
        $client = new Client([
            'timeout' => $this->getTimeout(),
            'connect_timeout' => $this->getTimeout(),
        ]);
        try {
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->getApiKey(),
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => [
                    'model' => $this->getModel(),
                    'max_tokens' => $maxTokens,
                    'temperature' => $this->getTemperature(),
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ],
                'http_errors' => false,
            ]);
        } catch (RequestException $exception) {
            $handlerContext = $exception->getHandlerContext();
            $errno = isset($handlerContext['errno']) ? (int) $handlerContext['errno'] : 0;
            $timedOut = (bool) ($handlerContext['timed_out'] ?? false);
            $error = isset($handlerContext['error']) ? (string) $handlerContext['error'] : $exception->getMessage();
            $message = $timedOut || (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT)
                ? sprintf('Anthropic request timed out after %d seconds', $this->getTimeout())
                : 'Anthropic request failed: ' . $error;
            throw new AIProviderException($message);
        }
        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * Extracts the primary text content from an Anthropic response body.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('Anthropic returned invalid JSON');
        }
        $content = $decoded['content'][0]['text'] ?? null;
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new AIProviderException('Anthropic response missing content');
        }
        return $content;
    }

    /**
     * Marks Anthropic rate limiting and server failures as retryable.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Returns the default Anthropic model used when none is configured.
     */
    protected function getDefaultModel(): string
    {
        return 'claude-3-5-sonnet-20240620';
    }
}
