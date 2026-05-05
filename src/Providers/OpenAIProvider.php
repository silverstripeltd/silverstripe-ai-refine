<?php

namespace SilverstripeLtd\AiBrandVoice\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;

/**
 * Provider integration for OpenAI chat completions.
 */
class OpenAIProvider extends AbstractAIProvider
{
    /**
     * Sends one evaluation request to the OpenAI chat completions endpoint.
     */
    protected function performRequest(string $systemPrompt, string $userPrompt, int $maxTokens): array
    {
        $client = new Client([
            'timeout' => $this->getTimeout(),
            'connect_timeout' => $this->getTimeout(),
        ]);
        try {
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getApiKey(),
                ],
                'json' => [
                    'model' => $this->getModel(),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => $this->getTemperature(),
                    'max_tokens' => $maxTokens,
                ],
                'http_errors' => false,
            ]);
        } catch (RequestException $exception) {
            $handlerContext = $exception->getHandlerContext();
            $errno = isset($handlerContext['errno']) ? (int) $handlerContext['errno'] : 0;
            $timedOut = (bool) ($handlerContext['timed_out'] ?? false);
            $error = isset($handlerContext['error']) ? (string) $handlerContext['error'] : $exception->getMessage();
            $message = $timedOut || (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT)
                ? sprintf('OpenAI request timed out after %d seconds', $this->getTimeout())
                : 'OpenAI request failed: ' . $error;
            throw new AIProviderException($message);
        }
        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * Extracts the assistant message content from an OpenAI response body.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('OpenAI returned invalid JSON');
        }
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new AIProviderException('OpenAI response missing content');
        }
        return $content;
    }

    /**
     * Marks OpenAI rate limiting and server failures as retryable.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Returns the default OpenAI model used when none is configured.
     */
    protected function getDefaultModel(): string
    {
        return 'gpt-4.1-mini';
    }
}
