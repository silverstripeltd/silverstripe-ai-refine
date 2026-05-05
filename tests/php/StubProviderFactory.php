<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use SilverstripeLtd\AiBrandVoice\Providers\AbstractAIProvider;
use SilverstripeLtd\AiBrandVoice\Providers\ProviderFactory;

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
