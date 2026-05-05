<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Exceptions;

use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests AIProviderException flag handling.
 */
class AIProviderExceptionTest extends SapphireTest
{
    /**
     * Confirms the fatal flag survives construction and can be read later.
     */
    public function testFatalFlagPersists(): void
    {
        $exception = new AIProviderException('Boom', true);

        $this->assertTrue($exception->isFatal());
    }
}
