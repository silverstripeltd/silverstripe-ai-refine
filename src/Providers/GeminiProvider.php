<?php

namespace SilverstripeLtd\AiRefine\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;

/**
 * Provider integration for Google Gemini.
 */
class GeminiProvider extends AbstractAIProvider
{
    /**
     * Sends one evaluation request to the Gemini generateContent endpoint.
     */
    protected function performRequest(string $systemPrompt, string $userPrompt, int $maxTokens): array
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($this->getModel())
        );
        $payload = [
            'systemInstruction' => [
                'role' => 'system',
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $this->getTemperature(),
                'maxOutputTokens' => $maxTokens,
            ],
        ];
        $thinkingLevel = $this->getThinkingLevel();
        if ($thinkingLevel !== 'none') {
            $payload['generationConfig']['thinkingConfig'] = [
                'thinkingLevel' => $thinkingLevel,
            ];
        }
        $client = new Client([
            'timeout' => $this->getTimeout(),
            'connect_timeout' => $this->getTimeout(),
        ]);
        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->getApiKey(),
                ],
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (RequestException $exception) {
            $handlerContext = $exception->getHandlerContext();
            $errno = isset($handlerContext['errno']) ? (int) $handlerContext['errno'] : 0;
            $timedOut = (bool) ($handlerContext['timed_out'] ?? false);
            $error = isset($handlerContext['error']) ? (string) $handlerContext['error'] : $exception->getMessage();
            $message = $timedOut || (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT)
                ? sprintf('Gemini request timed out after %d seconds', $this->getTimeout())
                : 'Gemini request failed: ' . $error;
            throw new AIProviderException($message);
        }
        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * Extracts concatenated text parts from a Gemini response body.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('Gemini returned invalid JSON');
        }
        $parts = $decoded['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            throw new AIProviderException('Gemini response missing content');
        }
        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new AIProviderException('Gemini response contained no text');
        }
        return $text;
    }

    /**
     * Marks Gemini rate limiting and server failures as retryable.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Returns the default Gemini model used when none is configured.
     */
    protected function getDefaultModel(): string
    {
        return 'gemini-3.1-flash-lite';
    }
}
