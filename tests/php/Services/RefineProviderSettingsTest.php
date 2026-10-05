<?php

namespace SilverstripeLtd\AiRefine\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiRefine\Services\RefineCompletionService;
use SilverstripeLtd\AiRefine\Tests\ProviderEnvironment;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers how the refine module resolves its provider, model and generation defaults.
 */
class RefineProviderSettingsTest extends SapphireTest
{
    protected $usesDatabase = false;

    private array $originalEnv = [];

    /**
     * Starts every test with no provider variables set.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = ProviderEnvironment::clear();
    }

    /**
     * Restores provider variables.
     */
    protected function tearDown(): void
    {
        ProviderEnvironment::restore($this->originalEnv);
        parent::tearDown();
    }

    /**
     * Confirms the module defaults to Gemini with its own limits.
     */
    public function testDefaultsToGeminiWithModuleLimits(): void
    {
        $settings = RefineCompletionService::create()->getSettings();
        $this->assertSame('gemini', $settings->getProviderName());
        $this->assertSame('gemini-3.1-flash-lite', $settings->getModel());
        $this->assertSame(20000, $settings->getMaxTokens());
        $this->assertSame(15, $settings->getTimeoutSeconds());
        $this->assertSame(0.0, $settings->getTemperature());
        $this->assertSame('low', $settings->getThinkingLevel());
    }

    /**
     * Provides the per-provider defaults the module ships.
     */
    public static function provideProviderDefaults(): array
    {
        return [
            'anthropic' => ['anthropic', AnthropicProvider::class, 'claude-haiku-4-5', 0.0, null],
            'openai' => ['openai', OpenAIProvider::class, 'gpt-5-mini', 1.0, null],
            'gemini' => ['gemini', GeminiProvider::class, 'gemini-3.1-flash-lite', 0.0, 'low'],
        ];
    }

    /**
     * Confirms each provider gets its own model, temperature and thinking level defaults.
     */
    #[DataProvider('provideProviderDefaults')]
    public function testSelectsProviderWithItsDefaults(
        string $name,
        string $class,
        string $model,
        float $temperature,
        ?string $thinkingLevel
    ): void {
        Environment::setEnv('AI_REFINE_PROVIDER', $name);
        $settings = RefineCompletionService::create()->getSettings();
        $provider = Injector::inst()->get(ProviderFactory::class)->forSettings($settings);
        $options = $provider->getDefaultOptions();
        $this->assertInstanceOf($class, $provider);
        $this->assertSame($model, $options->model);
        $this->assertSame(20000, $options->maxTokens);
        $this->assertSame(15, $options->timeoutSeconds);
        $this->assertSame($temperature, $options->temperature);
        $this->assertSame($thinkingLevel, $options->reasoningEffort);
    }

    /**
     * Confirms AI_REFINE_* variables override the YAML defaults.
     */
    public function testModuleEnvironmentVariablesOverrideDefaults(): void
    {
        Environment::setEnv('AI_REFINE_MODEL', 'custom-model');
        Environment::setEnv('AI_REFINE_MAX_TOKENS', '500');
        Environment::setEnv('AI_REFINE_REQUEST_TIMEOUT', '12');
        Environment::setEnv('AI_REFINE_TEMPERATURE', '0.35');
        Environment::setEnv('AI_REFINE_THINKING_LEVEL', 'none');
        Environment::setEnv('AI_REFINE_API_KEY', 'refine-key');
        $settings = RefineCompletionService::create()->getSettings();
        $this->assertSame('custom-model', $settings->getModel());
        $this->assertSame(500, $settings->getMaxTokens());
        $this->assertSame(12, $settings->getTimeoutSeconds());
        $this->assertSame(0.35, $settings->getTemperature());
        $this->assertSame('none', $settings->getThinkingLevel());
        $this->assertSame('refine-key', $settings->getApiKey());
    }

    /**
     * Confirms the shared AI_* variables are used when the module sets none of its own.
     */
    public function testSharedVariablesAreTheFallback(): void
    {
        Environment::setEnv('AI_PROVIDER', 'anthropic');
        Environment::setEnv('AI_API_KEY', 'shared-key');
        Environment::setEnv('AI_REQUEST_TIMEOUT', '40');
        $settings = RefineCompletionService::create()->getSettings();
        $this->assertSame('anthropic', $settings->getProviderName());
        $this->assertSame('shared-key', $settings->getApiKey());
        $this->assertSame('claude-haiku-4-5', $settings->getModel());
        $this->assertSame(40, $settings->getTimeoutSeconds());
    }

    /**
     * Confirms the module variables win over the shared ones.
     */
    public function testModuleVariablesWinOverSharedOnes(): void
    {
        Environment::setEnv('AI_PROVIDER', 'anthropic');
        Environment::setEnv('AI_API_KEY', 'shared-key');
        Environment::setEnv('AI_REFINE_PROVIDER', 'anthropic');
        Environment::setEnv('AI_REFINE_API_KEY', 'refine-key');
        $this->assertSame('refine-key', RefineCompletionService::create()->getSettings()->getApiKey());
    }

    /**
     * Confirms an unknown provider name is a blocking failure.
     */
    public function testUnknownProviderIsBlocking(): void
    {
        Environment::setEnv('AI_REFINE_PROVIDER', 'unknown');
        $settings = RefineCompletionService::create()->getSettings();
        try {
            Injector::inst()->get(ProviderFactory::class)->forSettings($settings);
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertStringContainsString('unknown', $exception->getMessage());
        }
    }
}
