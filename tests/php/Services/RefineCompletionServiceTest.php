<?php

namespace SilverstripeLtd\AiRefine\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiCore\Testing\StubProviderFactory;
use SilverstripeLtd\AiRefine\Services\RefineCompletionService;
use SilverstripeLtd\AiRefine\Services\RefinePromptService;
use SilverstripeLtd\AiRefine\Tests\ProviderEnvironment;
use SilverstripeLtd\AiRefine\Tests\RefineReplies;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
use SilverstripeLtd\AiRefine\ValueObjects\RefineSuggestion;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers the refine evaluation request, its settings and the parsing of provider replies.
 */
class RefineCompletionServiceTest extends SapphireTest
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
     * Restores provider variables and the real provider factory.
     */
    protected function tearDown(): void
    {
        ProviderEnvironment::restore($this->originalEnv);
        Injector::inst()->unregisterNamedObject(ProviderFactory::class);
        parent::tearDown();
    }

    /**
     * Confirms a plain JSON reply becomes a full result with typed suggestions.
     */
    public function testParsesSharedEvaluationJsonResponse(): void
    {
        $this->script('{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
            . '"suggestions":[{"targetKey":"page:title","targetType":"page_title",'
            . '"suggestedContent":"Updated title"}]}');
        $result = RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
        $this->assertSame('Good', $result->rating);
        $this->assertSame('Mostly on-brand.', $result->reasoningSummary);
        $this->assertCount(1, $result->suggestions);
        $this->assertInstanceOf(RefineSuggestion::class, $result->suggestions[0]);
        $this->assertSame('page:title', $result->suggestions[0]->targetKey);
        $this->assertSame('page_title', $result->suggestions[0]->targetType);
        $this->assertSame('Updated title', $result->suggestions[0]->suggestedContent);
    }

    /**
     * Confirms JSON wrapped in a code fence is recovered.
     */
    public function testParsesSharedEvaluationJsonResponseFromWrappedJson(): void
    {
        $this->script("```json\n"
            . '{"rating":"Excellent","reasoningSummary":"Strong match.",'
            . '"suggestions":[{"targetKey":"page:content","targetType":"page_content",'
            . '"suggestedContent":"<p>Updated body</p>"}]}'
            . "\n```");
        $result = RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
        $this->assertSame('Excellent', $result->rating);
        $this->assertSame('Strong match.', $result->reasoningSummary);
        $this->assertCount(1, $result->suggestions);
        $this->assertSame('<p>Updated body</p>', $result->suggestions[0]->suggestedContent);
    }

    /**
     * Confirms element text targets are accepted.
     */
    public function testParsesElementTextSuggestionTargetType(): void
    {
        $this->script('{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
            . '"suggestions":[{"targetKey":"element:4:field:myfield","targetType":"element_text",'
            . '"suggestedContent":"Updated block title"}]}');
        $result = RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
        $this->assertCount(1, $result->suggestions);
        $this->assertSame('element_text', $result->suggestions[0]->targetType);
        $this->assertSame('Updated block title', $result->suggestions[0]->suggestedContent);
    }

    /**
     * Confirms a reply without suggestions is rejected.
     */
    public function testMissingRequiredSuggestionsKeyThrowsProviderException(): void
    {
        $this->script('{"rating":"Good","reasoningSummary":"Mostly on-brand."}');
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('AI provider response missing suggestions');
        RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
    }

    /**
     * Confirms an unknown target type with no matching rewrite target is rejected.
     */
    public function testInvalidSuggestionTargetTypeThrowsProviderException(): void
    {
        $this->script('{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
            . '"suggestions":[{"targetKey":"page:title","targetType":"bad","suggestedContent":"Updated"}]}');
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('AI provider response missing a valid suggestion targetType');
        RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
    }

    /**
     * Confirms an unknown target type falls back to the matching rewrite target.
     */
    public function testInvalidSuggestionTargetTypeFallsBackToKnownRewriteTarget(): void
    {
        $this->script('{"rating":"Good","reasoningSummary":"Mostly on-brand.",'
            . '"suggestions":[{"targetKey":"element:4:field:myfield",'
            . '"targetType":"content_block","suggestedContent":"Updated"}]}');
        $result = RefineCompletionService::create()->evaluateRefine(
            'content',
            'Page title',
            'Refine definition',
            [
                new RefineRewriteTarget(
                    'element:4:field:myfield',
                    RefineRewriteTarget::TYPE_ELEMENT_TEXT,
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
     * Confirms an unreadable reply is a permanent, non-blocking failure.
     */
    public function testMalformedJsonIsNotTransientOrBlocking(): void
    {
        $this->script('I cannot rate this page.');
        try {
            RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertSame('AI provider returned malformed JSON', $exception->getMessage());
            $this->assertFalse($exception->isTransient());
            $this->assertFalse($exception->isBlocking());
        }
    }

    /**
     * Confirms the refine prompts are sent as one system prompt and one user turn.
     */
    public function testSendsRefinePromptsAsOneTurn(): void
    {
        $provider = $this->script(RefineReplies::json(RefineReplies::defaultResult()));
        RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
        [$systemPrompt, $userPrompt] = Injector::inst()->get(RefinePromptService::class)
            ->buildEvaluationPrompts('content', 'Page title', 'Refine definition', []);
        $request = $provider->getLastRequest();
        $this->assertSame($systemPrompt, $request->system);
        $this->assertCount(1, $request->messages);
        $this->assertSame($userPrompt, $request->messages[0]->getText());
    }

    /**
     * Confirms authentication failures stop processing.
     */
    public function testAuthenticationFailuresAreBlocking(): void
    {
        Environment::setEnv('AI_REFINE_API_KEY', 'test-key');
        $mock = $this->registerGemini([new Response(401, [], '{"error":{"message":"Invalid API key"}}')]);
        try {
            RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertStringContainsString('401', $exception->getMessage());
            $this->assertSame(0, $mock->count());
        }
    }

    /**
     * Confirms server errors are transient and the request is not retried.
     */
    public function testTransientFailuresAreNotRetried(): void
    {
        Environment::setEnv('AI_REFINE_API_KEY', 'test-key');
        $mock = $this->registerGemini([
            new Response(500, [], '{"error":{"message":"Temporary failure"}}'),
            new Response(200, [], '{}'),
        ]);
        try {
            RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isTransient());
            $this->assertFalse($exception->isBlocking());
            $this->assertSame(1, $mock->count());
        }
    }

    /**
     * Confirms a missing API key is blocking and nothing is sent.
     */
    public function testMissingApiKeyIsBlocking(): void
    {
        $mock = $this->registerGemini([new Response(200, [], '{}')]);
        try {
            RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertStringContainsString('AI_REFINE_API_KEY', $exception->getMessage());
            $this->assertSame(1, $mock->count());
        }
    }

    /**
     * Confirms AI_REFINE_REWRITE_MAX_TOKENS sets the output budget when AI_REFINE_MAX_TOKENS is unset.
     */
    public function testRewriteMaxTokensAliasAppliesWhenMaxTokensIsUnset(): void
    {
        Environment::setEnv('AI_REFINE_REWRITE_MAX_TOKENS', '3000');
        $provider = $this->script(RefineReplies::json(RefineReplies::defaultResult()));
        RefineCompletionService::create()->evaluateRefine('content', 'Page title', 'Refine definition');
        $this->assertSame(3000, $provider->getLastRequest()->options->maxTokens);
    }

    /**
     * Confirms AI_REFINE_MAX_TOKENS wins over the rewrite alias.
     */
    public function testMaxTokensWinsOverRewriteAlias(): void
    {
        Environment::setEnv('AI_REFINE_MAX_TOKENS', '4000');
        Environment::setEnv('AI_REFINE_REWRITE_MAX_TOKENS', '3000');
        $this->assertNull(RefineCompletionService::create()->getCompletionOptions());
        $this->assertSame(4000, RefineCompletionService::create()->getSettings()->getMaxTokens());
    }

    /**
     * Queues one scripted reply and registers the provider for every completion.
     */
    private function script(string $reply): ScriptedProvider
    {
        $provider = RefineReplies::sequence([$reply]);
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
        return $provider;
    }

    /**
     * Registers a real Gemini provider whose HTTP client replays the given responses.
     */
    private function registerGemini(array $responses): MockHandler
    {
        $mock = new MockHandler($responses);
        $provider = new GeminiProvider(
            new Client(['handler' => HandlerStack::create($mock)]),
            RefineCompletionService::create()->getSettings()
        );
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
        return $mock;
    }
}
