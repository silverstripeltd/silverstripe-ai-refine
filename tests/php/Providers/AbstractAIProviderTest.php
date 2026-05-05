<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Providers;

use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverstripeLtd\AiBrandVoice\Tests\TestAIProvider;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceRewriteTarget;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceSuggestion;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests shared provider parsing and error classification.
 */
class AbstractAIProviderTest extends SapphireTest
{
    /**
     * Seeds a fake API key before each provider parsing test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('AI_BRAND_VOICE_API_KEY', 'test-key');
    }

    /**
     * Clears provider-related environment overrides after each test.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_API_KEY', null);
        Environment::setEnv('AI_BRAND_VOICE_REQUEST_TIMEOUT', null);
        Environment::setEnv('AI_BRAND_VOICE_TEMPERATURE', null);

        parent::tearDown();
    }

    /**
     * Confirms valid shared evaluation JSON is parsed into a full result.
     */
    public function testParsesSharedEvaluationJsonResponse(): void
    {
        $provider = new TestAIProvider([
            [
                'status' => 200,
                'body' => '{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
                    . '"suggestions":[{"targetKey":"page:title","targetType":"page_title",'
                    . '"suggestedContent":"Updated title"}]}',
            ],
        ]);

        $result = $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');

        $this->assertSame('Good', $result->rating);
        $this->assertSame('Mostly on-brand.', $result->reasoningSummary);
        $this->assertCount(1, $result->suggestions);
        $this->assertInstanceOf(BrandVoiceSuggestion::class, $result->suggestions[0]);
        $this->assertSame('page:title', $result->suggestions[0]->targetKey);
        $this->assertSame('page_title', $result->suggestions[0]->targetType);
        $this->assertSame('Updated title', $result->suggestions[0]->suggestedContent);
    }

    /**
     * Confirms wrapped JSON code fences are stripped before parsing.
     */
    public function testParsesSharedEvaluationJsonResponseFromWrappedJson(): void
    {
        $provider = new TestAIProvider([
            [
                'status' => 200,
                'body' => "```json\n"
                    . '{"rating":"Excellent","reasoningSummary":"Strong match.",'
                    . '"suggestions":[{"targetKey":"page:content","targetType":"page_content",'
                    . '"suggestedContent":"<p>Updated body</p>"}]}'
                    . "\n```",
            ],
        ]);

        $result = $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');

        $this->assertSame('Excellent', $result->rating);
        $this->assertSame('Strong match.', $result->reasoningSummary);
        $this->assertCount(1, $result->suggestions);
        $this->assertSame('<p>Updated body</p>', $result->suggestions[0]->suggestedContent);
    }

    /**
     * Confirms element text targets are preserved when parsing suggestions.
     */
    public function testParsesElementTextSuggestionTargetType(): void
    {
        $provider = new TestAIProvider([
            [
                'status' => 200,
                'body' => '{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
                    . '"suggestions":[{"targetKey":"element:4:field:myfield","targetType":"element_text",'
                    . '"suggestedContent":"Updated block title"}]}',
            ],
        ]);

        $result = $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('element_text', $result->suggestions[0]->targetType);
        $this->assertSame('Updated block title', $result->suggestions[0]->suggestedContent);
    }

    /**
     * Confirms missing suggestion payloads are rejected.
     */
    public function testMissingRequiredSuggestionsKeyThrowsProviderException(): void
    {
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => '{"rating":"Good","reasoningSummary":"Mostly on-brand."}'],
        ]);

        $this->expectException(AIProviderException::class);
        $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');
    }

    /**
     * Confirms invalid suggestion target types are rejected when no rewrite target can resolve them.
     */
    public function testInvalidSuggestionTargetTypeThrowsProviderException(): void
    {
        $provider = new TestAIProvider([
            [
                'status' => 200,
                'body' => '{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
                    . '"suggestions":[{"targetKey":"page:title","targetType":"bad","suggestedContent":"Updated"}]}',
            ],
        ]);

        $this->expectException(AIProviderException::class);
        $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');
    }

    /**
     * Confirms known rewrite targets can recover an invalid target type from provider output.
     */
    public function testInvalidSuggestionTargetTypeFallsBackToKnownRewriteTarget(): void
    {
        $provider = new TestAIProvider([
            [
                'status' => 200,
                'body' => '{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
                    . '"suggestions":[{"targetKey":"element:4:field:myfield",'
                    . '"targetType":"content_block","suggestedContent":"Updated"}]}',
            ],
        ]);

        $result = $provider->evaluateBrandVoice(
            'content',
            'Page title',
            'Brand voice definition',
            [
                new BrandVoiceRewriteTarget(
                    'element:4:field:myfield',
                    BrandVoiceRewriteTarget::TYPE_ELEMENT_TEXT,
                    'MyField',
                    4,
                    'Original text'
                ),
            ]
        );

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('element_text', $result->suggestions[0]->targetType);
        $this->assertSame('Updated', $result->suggestions[0]->suggestedContent);
    }

    /**
     * Confirms authentication failures are treated as fatal provider errors.
     */
    public function testAuthenticationFailuresAreFatal(): void
    {
        $provider = new TestAIProvider([
            ['status' => 401, 'body' => '{"error":{"message":"Invalid API key"}}'],
        ]);

        try {
            $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');
            $this->fail('Expected provider exception to be thrown.');
        } catch (AIProviderException $exception) {
            $this->assertTrue($exception->isFatal());
            $this->assertSame('Invalid API key', $exception->getMessage());
        }
    }

    /**
     * Confirms transient upstream failures remain non-fatal.
     */
    public function testTransientFailuresAreNotFatal(): void
    {
        $provider = new TestAIProvider([
            ['status' => 500, 'body' => '{"error":{"message":"Temporary failure"}}'],
        ]);

        try {
            $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');
            $this->fail('Expected provider exception to be thrown.');
        } catch (AIProviderException $exception) {
            $this->assertFalse($exception->isFatal());
            $this->assertSame(1, $provider->callCount);
        }
    }

    /**
     * Confirms a missing API key is treated as a fatal configuration error.
     */
    public function testMissingApiKeyIsFatal(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_API_KEY', null);
        $provider = new TestAIProvider([]);

        try {
            $provider->evaluateBrandVoice('content', 'Page title', 'Brand voice definition');
            $this->fail('Expected provider exception to be thrown.');
        } catch (AIProviderException $exception) {
            $this->assertTrue($exception->isFatal());
        }
    }

    /**
     * Confirms the request timeout can be overridden through the environment.
     */
    public function testTimeoutUsesEnv(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_REQUEST_TIMEOUT', '12');
        $provider = new TestAIProvider([]);

        $this->assertSame(12, $provider->getResolvedTimeout());
    }

    /**
     * Confirms provider temperature defaults to zero for stable ratings.
     */
    public function testTemperatureDefaultsToZeroForStableRatings(): void
    {
        $provider = new TestAIProvider([]);

        $this->assertSame(0.0, $provider->getResolvedTemperature());
    }

    /**
     * Confirms the provider temperature can be overridden through the environment.
     */
    public function testTemperatureUsesEnvOverride(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_TEMPERATURE', '0.35');
        $provider = new TestAIProvider([]);

        $this->assertSame(0.35, $provider->getResolvedTemperature());
    }
}
