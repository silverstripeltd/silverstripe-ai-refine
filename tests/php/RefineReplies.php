<?php

namespace SilverstripeLtd\AiRefine\Tests;

use Closure;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiRefine\ValueObjects\RefineFullResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;
use SilverStripe\Dev\TestOnly;

/**
 * Builds scripted provider replies in the JSON shape the refine evaluation prompt asks for.
 */
class RefineReplies implements TestOnly
{
    /**
     * Number of times a repeating reply is queued, more than any single test needs.
     */
    public const REPEAT_COUNT = 20;

    /**
     * Returns the default evaluation used when a test does not need a specific one.
     */
    public static function defaultResult(): RefineFullResult
    {
        return new RefineFullResult('Good', 'Stub summary', [
            new RefineSuggestion('page:title', 'page_title', '', null, '', 'Updated title'),
        ]);
    }

    /**
     * Encodes an evaluation result as the JSON text a provider would return.
     */
    public static function json(RefineFullResult $result): string
    {
        $suggestions = [];
        foreach ($result->suggestions as $suggestion) {
            $suggestions[] = [
                'targetKey' => $suggestion->targetKey,
                'targetType' => $suggestion->targetType,
                'suggestedContent' => $suggestion->suggestedContent,
            ];
        }
        return json_encode([
            'rating' => $result->rating,
            'reasoningSummary' => $result->reasoningSummary,
            'suggestions' => $suggestions,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns a provider that answers every request with the same evaluation, or throws the given failure.
     */
    public static function repeating(
        ?RefineFullResult $result = null,
        ?ProviderException $exception = null
    ): ScriptedProvider {
        return self::sequence(array_fill(0, self::REPEAT_COUNT, $exception ?: ($result ?: self::defaultResult())));
    }

    /**
     * Returns a provider that answers with each entry in turn, then with the default evaluation.
     *
     * @param array<int, RefineFullResult|ProviderException|string> $responses
     */
    public static function sequence(array $responses): ScriptedProvider
    {
        $provider = new ScriptedProvider();
        $responses = array_merge($responses, array_fill(0, self::REPEAT_COUNT, self::defaultResult()));
        foreach ($responses as $response) {
            $provider->queue(self::reply($response));
        }
        return $provider;
    }

    /**
     * Returns the number of evaluation requests the provider received.
     */
    public static function callCount(ScriptedProvider $provider): int
    {
        return count($provider->getRequests());
    }

    /**
     * Converts one scripted entry into a queued reply or a closure that throws.
     */
    private static function reply(RefineFullResult|ProviderException|string $response): ChatResponse|Closure
    {
        if ($response instanceof ProviderException) {
            return static function () use ($response): never {
                throw $response;
            };
        }
        return ScriptedProvider::text(is_string($response) ? $response : self::json($response));
    }
}
