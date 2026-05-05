<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Providers;

use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverstripeLtd\AiBrandVoice\Providers\AnthropicProvider;
use SilverstripeLtd\AiBrandVoice\Providers\GeminiProvider;
use SilverstripeLtd\AiBrandVoice\Providers\OpenAIProvider;
use SilverstripeLtd\AiBrandVoice\Providers\ProviderFactory;
use SilverstripeLtd\AiBrandVoice\Tests\StubProvider;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Ensures provider resolution respects environment configuration.
 */
class ProviderFactoryTest extends SapphireTest
{
    private StubProvider $geminiProvider;

    private StubProvider $openAiProvider;

    private StubProvider $anthropicProvider;

    /**
     * Registers test provider services for each supported provider key.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiProvider = new StubProvider();
        $this->openAiProvider = new StubProvider();
        $this->anthropicProvider = new StubProvider();

        Injector::inst()->registerService($this->geminiProvider, GeminiProvider::class);
        Injector::inst()->registerService($this->openAiProvider, OpenAIProvider::class);
        Injector::inst()->registerService($this->anthropicProvider, AnthropicProvider::class);
    }

    /**
     * Clears the configured provider environment override after each test.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_PROVIDER', null);

        parent::tearDown();
    }

    /**
     * Confirms Gemini is used when no provider has been configured.
     */
    public function testDefaultsToGeminiWhenEnvEmpty(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_PROVIDER', '');
        $factory = new ProviderFactory();

        $this->assertSame($this->geminiProvider, $factory->getProvider());
    }

    /**
     * Confirms the OpenAI provider can be selected via environment configuration.
     */
    public function testSelectsOpenAiProvider(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_PROVIDER', 'openai');
        $factory = new ProviderFactory();

        $this->assertSame($this->openAiProvider, $factory->getProvider());
    }

    /**
     * Confirms the Anthropic provider can be selected via environment configuration.
     */
    public function testSelectsAnthropicProvider(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_PROVIDER', 'anthropic');
        $factory = new ProviderFactory();

        $this->assertSame($this->anthropicProvider, $factory->getProvider());
    }

    /**
     * Confirms unsupported providers fail with a fatal provider exception.
     */
    public function testUnknownProviderThrowsFatalProviderException(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_PROVIDER', 'unknown');
        $factory = new ProviderFactory();

        try {
            $factory->getProvider();
            $this->fail('Expected provider exception to be thrown.');
        } catch (AIProviderException $exception) {
            $this->assertTrue($exception->isFatal());
        }
    }
}
