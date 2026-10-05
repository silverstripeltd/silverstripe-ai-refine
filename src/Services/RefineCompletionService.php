<?php

namespace SilverstripeLtd\AiRefine\Services;

use SilverstripeLtd\AiCore\Completion\CompletionOptions;
use SilverstripeLtd\AiCore\Completion\JsonCompletion;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiCore\Settings\ProviderSettingsInterface;
use SilverstripeLtd\AiRefine\ValueObjects\RefineFullResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRatingResult;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Sends refine evaluation prompts through the shared ai-core provider layer and parses the reply.
 */
class RefineCompletionService
{
    use Injectable;

    public const MODULE_PREFIX = 'REFINE';

    public const ENV_MAX_TOKENS = 'AI_REFINE_MAX_TOKENS';

    public const ENV_REWRITE_MAX_TOKENS = 'AI_REFINE_REWRITE_MAX_TOKENS';

    private const ALLOWED_RATINGS = [
        'Excellent',
        'Good',
        'Adequate',
        'NeedsWork',
        'Poor',
    ];

    private RefinePromptService $promptService;

    private ?ProviderFactory $providerFactory;

    private ProviderSettingsInterface $settings;

    /**
     * Builds the completion service with injectable prompt, provider and settings dependencies.
     */
    public function __construct(
        ?RefinePromptService $promptService = null,
        ?ProviderFactory $providerFactory = null,
        ?ProviderSettingsInterface $settings = null
    ) {
        $this->promptService = $promptService ?: Injector::inst()->get(RefinePromptService::class);
        $this->providerFactory = $providerFactory;
        $this->settings = $settings ?: EnvProviderSettings::forModule(self::MODULE_PREFIX);
    }

    /**
     * Evaluates content against the refine definition and returns the parsed rating and suggestions.
     *
     * @throws ProviderException
     */
    public function evaluateRefine(
        string $content,
        string $pageTitle,
        string $refineDefinition,
        array $rewriteTargets = []
    ): RefineFullResult {
        [$systemPrompt, $userPrompt] = $this->promptService->buildEvaluationPrompts(
            $content,
            $pageTitle,
            $refineDefinition,
            $rewriteTargets
        );
        $payload = JsonCompletion::create($this->settings, $this->providerFactory)
            ->completeJson($systemPrompt, $userPrompt, $this->getCompletionOptions());
        return $this->parseFullResult($payload, $rewriteTargets);
    }

    /**
     * Returns the provider settings for the refine module.
     */
    public function getSettings(): ProviderSettingsInterface
    {
        return $this->settings;
    }

    /**
     * Applies the AI_REFINE_REWRITE_MAX_TOKENS alias when AI_REFINE_MAX_TOKENS is not set.
     */
    public function getCompletionOptions(): ?CompletionOptions
    {
        if ($this->readPositiveEnvInt(self::ENV_MAX_TOKENS) !== null) {
            return null;
        }
        $alias = $this->readPositiveEnvInt(self::ENV_REWRITE_MAX_TOKENS);
        return $alias === null ? null : new CompletionOptions(maxTokens: $alias);
    }

    /**
     * Reads a positive integer environment value, or null when it is unset, blank or not positive.
     */
    private function readPositiveEnvInt(string $name): ?int
    {
        $value = Environment::getEnv($name);
        if ($value === null || $value === false || trim((string) $value) === '') {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /**
     * @throws ProviderException
     */
    private function parseRatingResult(array $payload): RefineRatingResult
    {
        $rating = $payload['rating'] ?? null;
        $reasoningSummary = $payload['reasoningSummary'] ?? null;
        if (!is_string($rating) || !in_array($rating, self::ALLOWED_RATINGS, true)) {
            throw new ProviderException('AI provider response missing a valid rating');
        }
        if (!is_string($reasoningSummary) || trim($reasoningSummary) === '') {
            throw new ProviderException('AI provider response missing reasoningSummary');
        }
        return new RefineRatingResult($rating, trim($reasoningSummary));
    }

    /**
     * @throws ProviderException
     */
    private function parseFullResult(array $payload, array $rewriteTargets = []): RefineFullResult
    {
        $ratingResult = $this->parseRatingResult($payload);
        $suggestions = $payload['suggestions'] ?? null;
        if (!is_array($suggestions)) {
            throw new ProviderException('AI provider response missing suggestions');
        }
        $parsedSuggestions = [];
        $seenTargetKeys = [];
        foreach ($suggestions as $suggestion) {
            $parsed = $this->parseSuggestion($suggestion, $rewriteTargets);
            if (isset($seenTargetKeys[$parsed->targetKey])) {
                throw new ProviderException(sprintf(
                    'AI provider response contains duplicate suggestions for target %s',
                    $parsed->targetKey
                ));
            }
            $seenTargetKeys[$parsed->targetKey] = true;
            $parsedSuggestions[] = $parsed;
        }
        return new RefineFullResult(
            $ratingResult->rating,
            $ratingResult->reasoningSummary,
            $parsedSuggestions
        );
    }

    /**
     * Validates one suggestion entry from the provider payload.
     *
     * @throws ProviderException
     */
    private function parseSuggestion(mixed $suggestion, array $rewriteTargets): RefineSuggestion
    {
        if (!is_array($suggestion)) {
            throw new ProviderException('AI provider response contains an invalid suggestion entry');
        }
        $targetKey = $suggestion['targetKey'] ?? null;
        $suggestedContent = $suggestion['suggestedContent'] ?? null;
        if (!is_string($targetKey) || trim($targetKey) === '') {
            throw new ProviderException('AI provider response missing suggestion targetKey');
        }
        $targetKey = trim($targetKey);
        $resolvedTargetType = $this->resolveSuggestionTargetType(
            $targetKey,
            $suggestion['targetType'] ?? null,
            $rewriteTargets
        );
        if ($resolvedTargetType === null) {
            throw new ProviderException('AI provider response missing a valid suggestion targetType');
        }
        if (!is_string($suggestedContent) || trim($suggestedContent) === '') {
            throw new ProviderException('AI provider response missing suggestion content');
        }
        return new RefineSuggestion(
            $targetKey,
            $resolvedTargetType,
            '',
            null,
            '',
            trim($suggestedContent)
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
            if (RefineRewriteTarget::isValidTargetType($normalisedTargetType)) {
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
}
