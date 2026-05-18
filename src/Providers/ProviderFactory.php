<?php

namespace SilverstripeLtd\AiRefine\Providers;

use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiRefine\Exceptions\AIProviderException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

/**
 * Resolves the configured AI provider implementation.
 */
class ProviderFactory
{
    private LoggerInterface $logger;

    /**
     * Builds the provider factory and resolves a default logger when needed.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * @throws AIProviderException
     */
    public function getProvider(): AbstractAIProvider
    {
        $provider = 'gemini';
        if (Environment::hasEnv('AI_REFINE_PROVIDER')) {
            $configured = Environment::getEnv('AI_REFINE_PROVIDER');
            if ($configured !== null && $configured !== '' && $configured !== false) {
                $provider = strtolower((string) $configured);
            }
        }
        $providerClass = match ($provider) {
            'gemini' => GeminiProvider::class,
            'openai' => OpenAIProvider::class,
            'anthropic' => AnthropicProvider::class,
            default => null,
        };
        if (!$providerClass) {
            $this->logger->warning('Unknown AI provider configured', ['provider' => $provider]);
            throw new AIProviderException('Configured AI provider is not supported', true);
        }
        return Injector::inst()->get($providerClass);
    }
}
