<?php

namespace SilverstripeLtd\AiRefine\Tests;

use SilverstripeLtd\AiRefine\Providers\AbstractAIProvider;
use SilverstripeLtd\AiRefine\Providers\ProviderFactory;

/**
 * Provider factory that always returns the supplied provider.
 */
class StubProviderFactory extends ProviderFactory
{
    /**
     * Stores the provider instance that should always be returned in tests.
     */
    public function __construct(private readonly AbstractAIProvider $provider)
    {
    }

    /**
     * Returns the preconfigured provider without consulting environment configuration.
     */
    public function getProvider(): AbstractAIProvider
    {
        return $this->provider;
    }
}
