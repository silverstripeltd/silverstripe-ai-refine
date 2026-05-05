<?php

namespace SilverstripeLtd\AiBrandVoice\Providers;

use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverstripeLtd\AiBrandVoice\Services\BrandVoicePromptService;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceFullResult;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceRatingResult;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceRewriteTarget;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceSuggestion;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

/**
 * Shared provider logic for brand voice evaluation requests.
 */
abstract class AbstractAIProvider
{
    private const ALLOWED_RATINGS = [
        'Excellent',
        'Good',
        'Adequate',
        'NeedsWork',
        'Poor',
    ];

    private BrandVoicePromptService $promptService;

    protected LoggerInterface $logger;

    /**
     * Builds the shared provider dependencies or resolves them from the injector.
     */
    public function __construct(?BrandVoicePromptService $promptService = null, ?LoggerInterface $logger = null)
    {
        $this->promptService = $promptService ?: Injector::inst()->get(BrandVoicePromptService::class);
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * @throws AIProviderException
     */
    public function evaluateBrandVoice(
        string $content,
        string $pageTitle,
        string $brandVoiceDefinition,
        array $rewriteTargets = []
    ): BrandVoiceFullResult {
        [$systemPrompt, $userPrompt] = $this->promptService->buildEvaluationPrompts(
            $content,
            $pageTitle,
            $brandVoiceDefinition,
            $rewriteTargets
        );
        return $this->parseFullResult(
            $this->requestContent($systemPrompt, $userPrompt, $this->getMaxTokens()),
            $rewriteTargets
        );
    }

    /**
     * Reads the provider API key from the environment.
     */
    protected function getApiKey(): string
    {
        if (!Environment::hasEnv('AI_BRAND_VOICE_API_KEY')) {
            return '';
        }
        $apiKey = Environment::getEnv('AI_BRAND_VOICE_API_KEY');
        return $apiKey !== false ? trim((string) $apiKey) : '';
    }

    /**
     * Resolves the model name from environment, config, or provider defaults.
     */
    protected function getModel(): string
    {
        $env = Environment::hasEnv('AI_BRAND_VOICE_MODEL') ? Environment::getEnv('AI_BRAND_VOICE_MODEL') : null;
        if ($env !== null && $env !== '' && $env !== false) {
            return (string) $env;
        }
        $configured = Config::inst()->get(static::class, 'model');
        if ($configured) {
            return (string) $configured;
        }
        return $this->getDefaultModel();
    }

    /**
     * Resolves the configured reasoning depth for providers that support it.
     */
    protected function getThinkingLevel(): string
    {
        $env = Environment::hasEnv('AI_BRAND_VOICE_THINKING_LEVEL')
            ? Environment::getEnv('AI_BRAND_VOICE_THINKING_LEVEL')
            : null;
        return $env !== null && $env !== '' && $env !== false ? (string) $env : 'low';
    }

    /**
     * Resolves the provider temperature override used for evaluation stability.
     */
    protected function getTemperature(): float
    {
        $env = Environment::hasEnv('AI_BRAND_VOICE_TEMPERATURE')
            ? Environment::getEnv('AI_BRAND_VOICE_TEMPERATURE')
            : null;
        return $env !== null && $env !== '' && $env !== false ? (float) $env : 0.0;
    }

    /**
     * Resolves the maximum response token budget for evaluation requests.
     */
    protected function getMaxTokens(): int
    {
        $env = Environment::hasEnv('AI_BRAND_VOICE_MAX_TOKENS')
            ? Environment::getEnv('AI_BRAND_VOICE_MAX_TOKENS')
            : null;
        if ($env === null || $env === '' || $env === false) {
            $env = Environment::hasEnv('AI_BRAND_VOICE_REWRITE_MAX_TOKENS')
                ? Environment::getEnv('AI_BRAND_VOICE_REWRITE_MAX_TOKENS')
                : null;
        }
        $value = $env !== null && $env !== '' && $env !== false ? (int) $env : 20000;
        return $value > 0 ? $value : 20000;
    }

    /**
     * Resolves the HTTP timeout used for provider requests.
     */
    protected function getTimeout(): int
    {
        $env = Environment::hasEnv('AI_BRAND_VOICE_REQUEST_TIMEOUT')
            ? Environment::getEnv('AI_BRAND_VOICE_REQUEST_TIMEOUT')
            : null;
        if ($env !== null && $env !== '' && $env !== false) {
            $timeout = (int) $env;
            if ($timeout > 0) {
                return $timeout;
            }
        }
        return 15;
    }

    /**
     * @throws AIProviderException
     */
    private function requestContent(string $systemPrompt, string $userPrompt, int $maxTokens): string
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            $this->logger->warning('AI provider API key missing', ['provider' => static::class]);
            throw new AIProviderException('AI_BRAND_VOICE_API_KEY is not configured', true);
        }
        $loggedFailure = false;
        try {
            $response = $this->performRequest($systemPrompt, $userPrompt, $maxTokens);
            $status = $response['status'] ?? 0;
            $body = $response['body'] ?? '';
            if ($status >= 400) {
                $message = $this->extractErrorMessage($body) ?: 'AI provider request failed';
                $this->logger->warning('AI provider request failed', [
                    'provider' => static::class,
                    'status' => $status,
                    'message' => $message,
                    'fatal' => $this->isFatalStatus($status),
                    'transient' => $this->isTransientStatus($status),
                ]);
                $loggedFailure = true;
                throw new AIProviderException($message, $this->isFatalStatus($status));
            }
            return $this->extractResponseContent($body);
        } catch (AIProviderException $exception) {
            if (!$loggedFailure) {
                $this->logger->warning('AI provider error', [
                    'provider' => static::class,
                    'message' => $exception->getMessage(),
                    'fatal' => $exception->isFatal(),
                ]);
            }
            throw $exception;
        }
    }

    /**
     * @throws AIProviderException
     */
    private function parseRatingResult(string $json): BrandVoiceRatingResult
    {
        $payload = $this->decodeJsonPayload($json);
        $rating = $payload['rating'] ?? null;
        $reasoningSummary = $payload['reasoningSummary'] ?? null;
        if (!is_string($rating) || !in_array($rating, self::ALLOWED_RATINGS, true)) {
            throw new AIProviderException('AI provider response missing a valid rating');
        }
        if (!is_string($reasoningSummary) || trim($reasoningSummary) === '') {
            throw new AIProviderException('AI provider response missing reasoningSummary');
        }
        return new BrandVoiceRatingResult($rating, trim($reasoningSummary));
    }

    /**
     * @throws AIProviderException
     */
    private function parseFullResult(string $json, array $rewriteTargets = []): BrandVoiceFullResult
    {
        $payload = $this->decodeJsonPayload($json);
        $ratingResult = $this->parseRatingResult($json);
        $suggestions = $payload['suggestions'] ?? null;
        if (!is_array($suggestions)) {
            throw new AIProviderException('AI provider response missing suggestions');
        }
        $parsedSuggestions = [];
        $seenTargetKeys = [];
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) {
                throw new AIProviderException('AI provider response contains an invalid suggestion entry');
            }
            $targetKey = $suggestion['targetKey'] ?? null;
            $targetType = $suggestion['targetType'] ?? null;
            $suggestedContent = $suggestion['suggestedContent'] ?? null;
            if (!is_string($targetKey) || trim($targetKey) === '') {
                throw new AIProviderException('AI provider response missing suggestion targetKey');
            }
            $targetKey = trim($targetKey);
            $resolvedTargetType = $this->resolveSuggestionTargetType($targetKey, $targetType, $rewriteTargets);
            if ($resolvedTargetType === null) {
                throw new AIProviderException('AI provider response missing a valid suggestion targetType');
            }
            if (!is_string($suggestedContent) || trim($suggestedContent) === '') {
                throw new AIProviderException('AI provider response missing suggestion content');
            }
            if (isset($seenTargetKeys[$targetKey])) {
                throw new AIProviderException(sprintf(
                    'AI provider response contains duplicate suggestions for target %s',
                    $targetKey
                ));
            }
            $seenTargetKeys[$targetKey] = true;
            $parsedSuggestions[] = new BrandVoiceSuggestion(
                $targetKey,
                $resolvedTargetType,
                '',
                null,
                '',
                trim($suggestedContent)
            );
        }
        return new BrandVoiceFullResult(
            $ratingResult->rating,
            $ratingResult->reasoningSummary,
            $parsedSuggestions
        );
    }

    /**
     * Resolves the target type from provider output or falls back to known rewrite targets.
     */
    private function resolveSuggestionTargetType(
        string $targetKey,
        mixed $targetType,
        array $rewriteTargets
    ): ?string {
        if (is_string($targetType)) {
            $normalisedTargetType = strtolower(trim($targetType));
            if (BrandVoiceRewriteTarget::isValidTargetType($normalisedTargetType)) {
                return $normalisedTargetType;
            }
        }
        foreach ($rewriteTargets as $rewriteTarget) {
            if ($rewriteTarget->targetKey === $targetKey) {
                return $rewriteTarget->targetType;
            }
        }
        return null;
    }

    /**
     * Decodes provider JSON, including payloads wrapped in extra text or code fences.
     *
     * @throws AIProviderException
     */
    private function decodeJsonPayload(string $json): array
    {
        $trimmed = trim($json);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        throw new AIProviderException('AI provider returned malformed JSON');
    }

    /**
     * Extracts a readable error message from a failed provider response body.
     */
    private function extractErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (isset($decoded['error']['message'])) {
                return (string) $decoded['error']['message'];
            }
            if (isset($decoded['message'])) {
                return (string) $decoded['message'];
            }
        }
        return '';
    }

    /**
     * Classifies provider status codes that should halt processing immediately.
     */
    private function isFatalStatus(int $statusCode): bool
    {
        return in_array($statusCode, [401, 403], true);
    }

    /**
     * Sends one provider-specific HTTP request and returns the raw response payload.
     */
    abstract protected function performRequest(string $systemPrompt, string $userPrompt, int $maxTokens): array;

    /**
     * @throws AIProviderException
     */
    abstract protected function extractResponseContent(string $body): string;

    /**
     * Indicates whether a provider status code represents a retryable failure.
     */
    abstract protected function isTransientStatus(int $statusCode): bool;

    /**
     * Returns the default model name for the concrete provider implementation.
     */
    abstract protected function getDefaultModel(): string;
}
