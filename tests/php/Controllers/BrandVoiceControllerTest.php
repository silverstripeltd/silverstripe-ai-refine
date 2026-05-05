<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Controllers;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementContent;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiBrandVoice\Controllers\BrandVoiceController;
use SilverstripeLtd\AiBrandVoice\Exceptions\AIProviderException;
use SilverstripeLtd\AiBrandVoice\Models\BrandVoiceAnalysis;
use SilverstripeLtd\AiBrandVoice\Providers\ProviderFactory;
use SilverstripeLtd\AiBrandVoice\Services\ContentExtractionService;
use SilverstripeLtd\AiBrandVoice\Tests\CETestElementalPage;
use SilverstripeLtd\AiBrandVoice\Tests\CETestUntemplatedBlock;
use SilverstripeLtd\AiBrandVoice\Tests\RestrictedBrandVoicePage;
use SilverstripeLtd\AiBrandVoice\Tests\StubProvider;
use SilverstripeLtd\AiBrandVoice\Tests\StubProviderFactory;
use SilverstripeLtd\AiBrandVoice\Tests\TestAIProvider;
use SilverstripeLtd\AiBrandVoice\Tests\TestLogger;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceFullResult;
use SilverstripeLtd\AiBrandVoice\ValueObjects\BrandVoiceSuggestion;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

/**
 * Functional tests for Brand Voice schema and check endpoints.
 */
class BrandVoiceControllerTest extends FunctionalTest
{
    protected static $extra_dataobjects = [
        BrandVoiceAnalysis::class,
        CETestElementalPage::class,
        CETestUntemplatedBlock::class,
        ElementContent::class,
        RestrictedBrandVoicePage::class,
        SiteConfig::class,
    ];

    protected static $required_extensions = [
        CETestElementalPage::class => [
            ElementalPageExtension::class,
        ],
    ];

    private StubProvider $provider;

    private LoggerInterface $originalLogger;

    private TestLogger $logger;

    /**
     * Boots the controller test fixture with auth, provider, and logger overrides.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
        SecurityToken::enable();

        $this->originalLogger = Injector::inst()->get(LoggerInterface::class);
        $this->logger = new TestLogger();
        Injector::inst()->registerService($this->logger, LoggerInterface::class);

        $this->provider = new StubProvider(
            new BrandVoiceFullResult('Good', 'Mostly aligned.', [
                new BrandVoiceSuggestion('page:title', 'page_title', '', null, '', 'Updated check page'),
                new BrandVoiceSuggestion('page:content', 'page_content', '', null, '', '<p>Rewritten section</p>'),
            ])
        );
        Injector::inst()->registerService(new StubProviderFactory($this->provider), ProviderFactory::class);

        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = 'We write in clear, practical, friendly language and keep every page'
            . ' easy to follow while still sounding human and specific.';
        $siteConfig->write();

        $this->session()->set(SecurityToken::inst()->getName(), SecurityToken::inst()->getValue());
    }

    /**
     * Restores the shared services and site config after each controller test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        Injector::inst()->registerService($this->originalLogger, LoggerInterface::class);
        Injector::inst()->registerService(new ProviderFactory(), ProviderFactory::class);

        parent::tearDown();
    }

    /**
     * Confirms the CMS boot config exposes the schema, check, and apply endpoints.
     */
    public function testClientConfigIncludesSchemaCheckAndApplyUrls(): void
    {
        $controller = BrandVoiceController::create();
        $controller->setRequest(new HTTPRequest('GET', '/admin/ai-brand-voice'));
        $config = $controller->getClientConfig();

        $this->assertSame('admin/ai-brand-voice/schema', $config['form']['brandVoiceCheck']['schemaUrl']);
        $this->assertSame('admin/ai-brand-voice/check', $config['form']['brandVoiceCheck']['checkUrl']);
        $this->assertSame('admin/ai-brand-voice/apply', $config['form']['brandVoiceCheck']['applyUrl']);
        $this->assertSame('ai-brand-voice-modal', $config['form']['brandVoiceCheck']['className']);
        $this->assertSame('.ai-brand-voice-modal', $config['form']['brandVoiceCheck']['modalSelector']);
    }

    /**
     * Confirms the schema endpoint returns the form schema plus modal metadata.
     */
    public function testSchemaEndpointReturnsSchemaAndMeta(): void
    {
        $page = $this->createPage('Schema page', '<p>Draft content</p>');

        $response = $this->get(
            '/admin/ai-brand-voice/schema/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('schema', $payload);
        $this->assertArrayHasKey('state', $payload);
        $this->assertSame(
            'This check evaluates your saved draft content. Save the page to draft before checking if you'
            . ' have unsaved changes.',
            $payload['meta']['brandVoice']['messages']['draftNotice'] ?? null
        );
        $this->assertSame(
            'Your content fully aligns with the brand voice. No changes needed.',
            $payload['meta']['brandVoice']['messages']['allAligned'] ?? null
        );
        $this->assertSame(
            'generic',
            $payload['meta']['brandVoice']['errors']['provider']['mode'] ?? null
        );
        $this->assertSame(
            'Brand voice suggestions applied to draft content',
            $payload['meta']['brandVoice']['messages']['applySuccess'] ?? null
        );
        $this->assertSame('Apply suggestions', $payload['meta']['brandVoice']['labels']['apply'] ?? null);
        $this->assertSame('Needs work', $payload['meta']['brandVoice']['ratingLabels']['NeedsWork'] ?? null);
        $this->assertSame(
            'Apply this suggestion',
            $payload['meta']['brandVoice']['labels']['applySuggestion'] ?? null
        );
        $this->assertArrayNotHasKey('keepSame', $payload['meta']['brandVoice']['labels'] ?? []);
        $this->assertSame(
            'admin/ai-brand-voice/apply/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            $payload['meta']['brandVoice']['actions']['applyUrl'] ?? null
        );
        $this->assertTrue($payload['meta']['brandVoice']['state']['supportsApply'] ?? false);
        $this->assertSame(
            Injector::inst()->get(ContentExtractionService::class)->extractForDraftCheck($page)->hash,
            $payload['meta']['brandVoice']['state']['contentHash'] ?? null
        );
        $this->assertFalse($payload['meta']['brandVoice']['state']['storesResultsServerSide'] ?? true);

        $fieldNames = array_map(
            static fn(array $field): ?string => $field['name'] ?? null,
            $payload['schema']['fields'] ?? []
        );
        $this->assertContains('BrandVoiceDraftNotice', $fieldNames);
        $this->assertContains('BrandVoiceEmptyState', $fieldNames);
        $this->assertContains('RatingDisplay', $fieldNames);
        $this->assertContains('ReasoningSummaryDisplay', $fieldNames);
        $this->assertContains('RewrittenContentDisplay', $fieldNames);
        $this->assertContains('BrandVoiceCopyAffordance', $fieldNames);

        $actionNames = array_map(
            static fn(array $action): ?string => $action['name'] ?? null,
            $payload['schema']['actions'] ?? []
        );
        $this->assertContains('action_BrandVoiceCheckAction', $actionNames);
    }

    /**
     * Confirms the schema metadata reflects an unconfigured Brand Voice definition.
     */
    public function testSchemaEndpointMarksBrandVoiceAsUnconfiguredWhenDefinitionMissing(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        $page = $this->createPage('Schema page', '<p>Draft content</p>');

        $response = $this->get(
            '/admin/ai-brand-voice/schema/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['meta']['brandVoice']['state']['brandVoiceConfigured'] ?? true);
        $this->assertSame(
            'No brand voice has been defined. Configure your brand voice in Settings > Brand Voice.',
            $payload['meta']['brandVoice']['messages']['missingBrandVoice'] ?? null
        );
    }

    /**
     * Confirms schema access is denied when the page cannot be edited.
     */
    public function testSchemaEndpointRejectsRestrictedPages(): void
    {
        $page = RestrictedBrandVoicePage::create([
            'Title' => 'Restricted schema page',
            'Content' => '<p>Draft content</p>',
        ]);
        $page->write();

        $response = $this->get(
            '/admin/ai-brand-voice/schema/' . $page->ID . '?fqcn=' . rawurlencode(RestrictedBrandVoicePage::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Access denied', $payload['error'] ?? null);
    }

    /**
     * Confirms the schema endpoint returns a not found error for missing pages.
     */
    public function testSchemaEndpointReturnsNotFoundForMissingPage(): void
    {
        $response = $this->get(
            '/admin/ai-brand-voice/schema/99999?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );

        $this->assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Record not found', $payload['error'] ?? null);
    }

    /**
     * Confirms the check endpoint returns draft suggestions without writing an analysis record.
     */
    public function testCheckEndpointReturnsDraftResultWithoutPersistingAnalysis(): void
    {
        $page = $this->createPage('Check page', '<p>Draft content</p>');

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Good', $payload['rating'] ?? null);
        $this->assertSame('Good', $payload['ratingLabel'] ?? null);
        $this->assertSame('Mostly aligned.', $payload['reasoningSummary'] ?? null);
        $this->assertCount(2, $payload['suggestions'] ?? []);
        $this->assertSame('page:title', $payload['suggestions'][0]['targetKey'] ?? null);
        $this->assertSame('Title', $payload['suggestions'][0]['fieldName'] ?? null);
        $this->assertSame('Page name', $payload['suggestions'][0]['fieldLabel'] ?? null);
        $this->assertSame('', $payload['suggestions'][0]['targetTitle'] ?? null);
        $this->assertSame('Check page', $payload['suggestions'][0]['sourceContent'] ?? null);
        $this->assertSame('Updated check page', $payload['suggestions'][0]['suggestedContent'] ?? null);
        $this->assertSame('text', $payload['suggestions'][0]['contentFormat'] ?? null);
        $this->assertStringContainsString('<del', $payload['suggestions'][0]['diffHtml'] ?? '');
        $this->assertStringContainsString('<ins', $payload['suggestions'][0]['diffHtml'] ?? '');
        $this->assertArrayNotHasKey('fieldScaffold', $payload['suggestions'][0] ?? []);
        $this->assertSame('page:content', $payload['suggestions'][1]['targetKey'] ?? null);
        $this->assertSame('Content', $payload['suggestions'][1]['fieldName'] ?? null);
        $this->assertSame('Content', $payload['suggestions'][1]['fieldLabel'] ?? null);
        $this->assertSame('', $payload['suggestions'][1]['targetTitle'] ?? null);
        $this->assertSame('Draft content', $payload['suggestions'][1]['sourceContent'] ?? null);
        $this->assertSame('<p>Rewritten section</p>', $payload['suggestions'][1]['suggestedContent'] ?? null);
        $this->assertSame('html', $payload['suggestions'][1]['contentFormat'] ?? null);
        $this->assertStringContainsString('<ins', $payload['suggestions'][1]['diffHtml'] ?? '');
        $this->assertStringContainsString('Rewritten section', $payload['suggestions'][1]['diffHtml'] ?? '');
        $this->assertArrayNotHasKey('fieldScaffold', $payload['suggestions'][1] ?? []);
        $this->assertSame(1, $this->provider->evaluationCallCount);
        $this->assertSame(
            0,
            BrandVoiceAnalysis::get()->filter([
                'ParentID' => $page->ID,
                'ParentClass' => $page->ClassName,
            ])->count()
        );
    }

    /**
     * Confirms HtmlDiff preserves paragraph structure for multi-block HTML suggestions.
     */
    public function testCheckEndpointPreservesOriginalHtmlStructureInDiffs(): void
    {
        $page = $this->createPage('Diff page', '<p>First paragraph</p><p>Second paragraph</p>');
        $provider = new StubProvider(new BrandVoiceFullResult('Good', 'Mostly aligned.', [
            new BrandVoiceSuggestion('page:title', 'page_title', '', null, '', 'Diff page'),
            new BrandVoiceSuggestion(
                'page:content',
                'page_content',
                '',
                null,
                '',
                '<p>Updated first paragraph</p><p>Updated second paragraph</p>'
            ),
        ]));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $sourceContent = $payload['suggestions'][1]['sourceContent'] ?? '';
        $diffHtml = $payload['suggestions'][1]['diffHtml'] ?? '';
        $this->assertStringContainsString('First paragraph', $sourceContent);
        $this->assertStringContainsString('Second paragraph', $sourceContent);
        $this->assertStringNotContainsString('<p>', $sourceContent);
        $this->assertSame(2, substr_count($diffHtml, '<del>'));
        $this->assertSame(2, substr_count($diffHtml, '<ins>'));
        $this->assertStringContainsString('</p>', $diffHtml);
    }

    /**
     * Confirms unsafe tags and attributes are stripped from diff HTML before it reaches the modal.
     */
    public function testCheckEndpointSanitisesDiffHtml(): void
    {
        $page = $this->createPage('Unsafe diff page', '<p>Current paragraph</p>');
        $provider = new StubProvider(new BrandVoiceFullResult('Good', 'Needs a safer rewrite.', [
            new BrandVoiceSuggestion('page:title', 'page_title', '', null, '', 'Unsafe diff page'),
            new BrandVoiceSuggestion(
                'page:content',
                'page_content',
                '',
                null,
                '',
                '<p>Safe paragraph</p><script>alert(1)</script><a href="javascript:alert(2)" onclick="alert(3)">'
                . 'Link text</a><img src="javascript:alert(4)" onerror="alert(5)"><svg><script>alert(6)</script></svg>'
            ),
        ]));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $diffHtml = $payload['suggestions'][1]['diffHtml'] ?? '';
        $this->assertStringContainsString('<ins', $diffHtml);
        $this->assertStringContainsString('<ins>Safe</ins>', $diffHtml);
        $this->assertStringContainsString('paragraph</p>', $diffHtml);
        $this->assertStringContainsString('Link text', $diffHtml);
        $this->assertStringNotContainsString('<script', $diffHtml);
        $this->assertStringNotContainsString('<a', $diffHtml);
        $this->assertStringNotContainsString('<img', $diffHtml);
        $this->assertStringNotContainsString('<svg', $diffHtml);
        $this->assertStringNotContainsString('href=', $diffHtml);
        $this->assertStringNotContainsString('onclick=', $diffHtml);
        $this->assertStringNotContainsString('javascript:', $diffHtml);
    }

    /**
     * Confirms Excellent results never send suggestions back to the modal.
     */
    public function testCheckEndpointStripsSuggestionsForExcellentResults(): void
    {
        $page = $this->createPage('Excellent page', '<p>Draft content</p>');
        $provider = new StubProvider(new BrandVoiceFullResult('Excellent', 'Fully aligned.', [
            new BrandVoiceSuggestion('page:title', 'page_title', '', null, '', 'Excellent page'),
            new BrandVoiceSuggestion('page:content', 'page_content', '', null, '', '<p>Updated content</p>'),
        ]));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Excellent', $payload['rating'] ?? null);
        $this->assertSame('Excellent', $payload['ratingLabel'] ?? null);
        $this->assertSame('Fully aligned.', $payload['reasoningSummary'] ?? null);
        $this->assertSame([], $payload['suggestions'] ?? null);
        $this->assertSame(1, $provider->evaluationCallCount);
    }

    /**
     * Confirms the controller maps the NeedsWork enum to its display label.
     */
    public function testCheckEndpointReturnsDisplayLabelForNeedsWorkRating(): void
    {
        $page = $this->createPage('Needs work page', '<p>Draft content</p>');
        $provider = new StubProvider(new BrandVoiceFullResult('NeedsWork', 'Needs work reasoning.', [
            new BrandVoiceSuggestion('page:title', 'page_title', '', null, '', 'Updated needs work page'),
            new BrandVoiceSuggestion('page:content', 'page_content', '', null, '', '<p>Updated content</p>'),
        ]));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('NeedsWork', $payload['rating'] ?? null);
        $this->assertSame('Needs work', $payload['ratingLabel'] ?? null);
    }

    /**
     * Confirms the check endpoint rejects requests when the site has no Brand Voice definition.
     */
    public function testCheckEndpointRejectsMissingBrandVoiceDefinition(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        $page = $this->createPage('No brand voice page', '<p>Draft content</p>');

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            'No brand voice has been defined. Configure your brand voice in Settings > Brand Voice.',
            $payload['error'] ?? null
        );
        $this->assertSame(0, $this->provider->evaluationCallCount);
    }

    /**
     * Confirms empty pages return the expected no-content error response.
     */
    public function testCheckEndpointReturnsEmptyContentResponse(): void
    {
        $page = $this->createPage('   ', '');

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('This page has no content to evaluate', $payload['error'] ?? null);
    }

    /**
     * Confirms restricted pages cannot be checked through the controller.
     */
    public function testCheckEndpointRejectsRestrictedPage(): void
    {
        $page = RestrictedBrandVoicePage::create([
            'Title' => 'Restricted check page',
            'Content' => '<p>Draft content</p>',
        ]);
        $page->write();

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(RestrictedBrandVoicePage::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Access denied', $payload['error'] ?? null);
    }

    /**
     * Confirms stale security tokens are rejected before a provider request is made.
     */
    public function testCheckEndpointRejectsStaleSecurityToken(): void
    {
        $page = $this->createPage('Check page', '<p>Draft content</p>');
        $this->session()->set(SecurityToken::inst()->getName(), 'fresh-security-token');

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => 'stale-security-token'],
            ['X-SecurityID' => 'stale-security-token']
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Session timed out, please refresh and try again.', $payload['error'] ?? null);
    }

    /**
     * Confirms test runs expose the generic provider error message instead of raw details.
     */
    public function testCheckEndpointUsesGenericProviderErrorInTests(): void
    {
        $page = $this->createPage('Provider page', '<p>Draft content</p>');
        $provider = new StubProvider(null, new AIProviderException('Provider boom', true));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(500, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('There was an error connecting to the AI provider', $payload['error'] ?? null);
    }

    /**
     * Confirms untitled or untemplated elemental blocks still resolve useful suggestion metadata.
     */
    public function testCheckEndpointHandlesUntemplatedElementalBlocks(): void
    {
        $page = Versioned::withVersionedMode(function (): CETestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);

            $page = CETestElementalPage::create([
                'Title' => 'Mixed blocks page',
                'Content' => '<p>Legacy fallback</p>',
            ]);
            $page->write();

            $page->ElementalArea()->Elements()->add(CETestUntemplatedBlock::create([
                'Title' => 'My content block',
                'MyField' => 'Untemplated block title',
                'MyBigField' => 'Untemplated block copy',
            ]));
            $page->ElementalArea()->Elements()->add(ElementContent::create([
                'HTML' => '<p>Supported block</p>',
            ]));
            return DataObject::get(CETestElementalPage::class)->byID($page->ID);
        });

        /** @var CETestUntemplatedBlock $customElement */
        $customElement = $page->ElementalArea()->Elements()
            ->filter('ClassName', CETestUntemplatedBlock::class)
            ->first();
        /** @var ElementContent $supportedElement */
        $supportedElement = $page->ElementalArea()->Elements()
            ->filter('ClassName', ElementContent::class)
            ->first();
        $provider = new StubProvider(new BrandVoiceFullResult('Good', 'Mostly aligned.', [
            new BrandVoiceSuggestion('page:title', 'page_title', '', null, '', 'Updated mixed blocks page'),
            new BrandVoiceSuggestion(
                sprintf('element:%d:field:myfield', $customElement->ID),
                'element_text',
                '',
                null,
                '',
                'Updated block title'
            ),
            new BrandVoiceSuggestion(
                sprintf('element:%d:field:mybigfield', $customElement->ID),
                'element_text',
                '',
                null,
                '',
                'Updated block copy'
            ),
            new BrandVoiceSuggestion(
                sprintf('element:%d:html', $supportedElement->ID),
                'element_html',
                '',
                null,
                '',
                '<p>Updated supported block</p>'
            ),
        ]));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

        $response = $this->post(
            '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(CETestElementalPage::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Good', $payload['rating'] ?? null);
        $this->assertCount(4, $payload['suggestions'] ?? []);
        $this->assertSame('page:title', $payload['suggestions'][0]['targetKey'] ?? null);
        $this->assertSame(
            sprintf('element:%d:field:myfield', $customElement->ID),
            $payload['suggestions'][1]['targetKey'] ?? null
        );
        $this->assertSame('My field', $payload['suggestions'][1]['fieldLabel'] ?? null);
        $this->assertSame('My content block', $payload['suggestions'][1]['targetTitle'] ?? null);
        $this->assertSame('text', $payload['suggestions'][1]['contentFormat'] ?? null);
        $this->assertStringContainsString('<ins', $payload['suggestions'][1]['diffHtml'] ?? '');
        $this->assertArrayNotHasKey('fieldScaffold', $payload['suggestions'][1] ?? []);
        $this->assertSame(
            sprintf('element:%d:field:mybigfield', $customElement->ID),
            $payload['suggestions'][2]['targetKey'] ?? null
        );
        $this->assertSame('My big field', $payload['suggestions'][2]['fieldLabel'] ?? null);
        $this->assertSame('My content block', $payload['suggestions'][2]['targetTitle'] ?? null);
        $this->assertSame('text', $payload['suggestions'][2]['contentFormat'] ?? null);
        $this->assertStringContainsString('<ins', $payload['suggestions'][2]['diffHtml'] ?? '');
        $this->assertArrayNotHasKey('fieldScaffold', $payload['suggestions'][2] ?? []);
        $this->assertSame(
            sprintf('element:%d:html', $supportedElement->ID),
            $payload['suggestions'][3]['targetKey'] ?? null
        );
        $this->assertSame('HTML', $payload['suggestions'][3]['fieldLabel'] ?? null);
        $this->assertSame('Content', $payload['suggestions'][3]['targetTitle'] ?? null);
        $this->assertSame('html', $payload['suggestions'][3]['contentFormat'] ?? null);
        $this->assertStringContainsString('<ins', $payload['suggestions'][3]['diffHtml'] ?? '');
        $this->assertArrayNotHasKey('fieldScaffold', $payload['suggestions'][3] ?? []);
    }

    /**
     * Confirms missing provider target types can be recovered from the extracted custom block targets.
     */
    public function testCheckEndpointRecoversMissingProviderTargetTypeForCustomBlocks(): void
    {
        Environment::setEnv('AI_BRAND_VOICE_API_KEY', 'test-key');

        try {
            $page = Versioned::withVersionedMode(function (): CETestElementalPage {
                Versioned::set_stage(Versioned::DRAFT);

                $page = CETestElementalPage::create([
                    'Title' => 'Broken blocks page',
                ]);
                $page->write();

                $page->ElementalArea()->Elements()->add(CETestUntemplatedBlock::create([
                    'Title' => 'Bad first block',
                    'MyField' => 'This badly worded intro block needs work',
                    'MyBigField' => 'Supporting copy for the same block',
                ]));
                return DataObject::get(CETestElementalPage::class)->byID($page->ID);
            });

            /** @var CETestUntemplatedBlock $customElement */
            $customElement = $page->ElementalArea()->Elements()->first();
            $provider = new TestAIProvider([
                [
                    'status' => 200,
                    'body' => json_encode([
                        'rating' => 'NeedsWork',
                        'reasoningSummary' => 'The first block sounds clumsy and off-brand.',
                        'suggestions' => [
                            [
                                'targetKey' => 'page:title',
                                'targetType' => 'page_title',
                                'suggestedContent' => 'Broken blocks page',
                            ],
                            [
                                'targetKey' => sprintf('element:%d:field:myfield', $customElement->ID),
                                'suggestedContent' => 'A clearer introduction that matches the brand voice',
                            ],
                            [
                                'targetKey' => sprintf('element:%d:field:mybigfield', $customElement->ID),
                                'targetType' => 'element_text',
                                'suggestedContent' => 'Supporting copy that stays direct and consistent',
                            ],
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                ],
            ]);
            Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

            $response = $this->post(
                '/admin/ai-brand-voice/check/' . $page->ID . '?fqcn=' . rawurlencode(CETestElementalPage::class),
                [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
                ['X-SecurityID' => SecurityToken::inst()->getValue()]
            );

            $this->assertSame(200, $response->getStatusCode());
            $payload = json_decode((string) $response->getBody(), true);
            $this->assertSame('NeedsWork', $payload['rating'] ?? null);
            $this->assertCount(3, $payload['suggestions'] ?? []);
            $this->assertSame(
                sprintf('element:%d:field:myfield', $customElement->ID),
                $payload['suggestions'][1]['targetKey'] ?? null
            );
            $this->assertSame('element_text', $payload['suggestions'][1]['targetType'] ?? null);
            $this->assertSame('MyField', $payload['suggestions'][1]['fieldName'] ?? null);
            $this->assertSame('My field', $payload['suggestions'][1]['fieldLabel'] ?? null);
            $this->assertSame('Bad first block', $payload['suggestions'][1]['targetTitle'] ?? null);
            $this->assertSame(
                'This badly worded intro block needs work',
                $payload['suggestions'][1]['sourceContent'] ?? null
            );
            $this->assertSame(
                'A clearer introduction that matches the brand voice',
                $payload['suggestions'][1]['suggestedContent'] ?? null
            );
        } finally {
            Environment::setEnv('AI_BRAND_VOICE_API_KEY', null);
        }
    }

    /**
     * Confirms HTML is stripped from custom elemental plain-text fields before apply writes them.
     */
    public function testApplyEndpointStripsHtmlFromCustomElementPlainTextFieldSuggestions(): void
    {
        $page = Versioned::withVersionedMode(function (): CETestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            $page = CETestElementalPage::create([
                'Title' => 'Custom block apply page',
            ]);
            $page->write();
            $page->ElementalArea()->Elements()->add(CETestUntemplatedBlock::create([
                'MyField' => 'Current block title',
                'MyBigField' => 'Current block copy',
            ]));
            return DataObject::get(CETestElementalPage::class)->byID($page->ID);
        });

        /** @var CETestUntemplatedBlock $customElement */
        $customElement = $page->ElementalArea()->Elements()
            ->filter('ClassName', CETestUntemplatedBlock::class)
            ->first();

        $response = $this->applySuggestions($page, [
            [
                'apply' => '1',
                'targetKey' => sprintf('element:%d:field:myfield', $customElement->ID),
                'targetType' => 'element_text',
                'targetId' => $customElement->ID,
                'fieldName' => 'MyField',
                'suggestedContent' => '<strong>Updated</strong> block title',
            ],
            [
                'apply' => '1',
                'targetKey' => sprintf('element:%d:field:mybigfield', $customElement->ID),
                'targetType' => 'element_text',
                'targetId' => $customElement->ID,
                'fieldName' => 'MyBigField',
                'suggestedContent' => '<p>Updated <em>block</em> copy</p>',
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(2, $payload['appliedCount'] ?? null);
        $this->assertSame(0, $payload['skippedCount'] ?? null);
        /** @var CETestUntemplatedBlock $updatedElement */
        $updatedElement = $this->getDraftRecord(CETestUntemplatedBlock::class, $customElement->ID);
        $this->assertSame('Updated block title', $updatedElement->MyField);
        $this->assertSame('Updated block copy', $updatedElement->MyBigField);
    }

    /**
     * Confirms page apply preserves clean HTML content and strips tags from plain-text fields.
     */
    public function testApplyEndpointSanitisesSelectedPageSuggestionsBeforeWritingDraft(): void
    {
        $page = $this->createPage('Apply page', '<p>Draft content</p>');
        $response = $this->applySuggestions($page, [
            [
                'apply' => '1',
                'targetKey' => 'page:title',
                'targetType' => 'page_title',
                'targetId' => $page->ID,
                'fieldName' => 'Title',
                'suggestedContent' => '<strong>Updated</strong> apply page',
            ],
            [
                'rewrite' => 'true',
                'targetKey' => 'page:content',
                'targetType' => 'page_content',
                'targetId' => $page->ID,
                'fieldName' => 'Content',
                'suggestedContent' => '<p>Updated <strong>draft</strong> content</p>',
            ],
            [
                'apply' => '0',
                'targetKey' => 'page:title',
                'targetType' => 'page_title',
                'targetId' => $page->ID,
                'fieldName' => 'Title',
                'suggestedContent' => 'Ignored duplicate',
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(2, $payload['appliedCount'] ?? null);
        $this->assertSame(0, $payload['skippedCount'] ?? null);
        $this->assertTrue($payload['reloadRequired'] ?? false);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecord(SiteTree::class, $page->ID);
        $this->assertSame('Updated apply page', $draftPage->Title);
        $this->assertSame('<p>Updated <strong>draft</strong> content</p>', $draftPage->Content);
    }

    /**
     * Confirms JSON apply requests sanitise dangerous HTML before it reaches draft content.
     */
    public function testApplyEndpointSanitisesDangerousHtmlFromJsonRequestBody(): void
    {
        $page = $this->createPage('JSON apply page', '<p>Draft content</p>');
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => 'page_title',
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Updated from JSON body',
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:content',
                    'targetType' => 'page_content',
                    'targetId' => $page->ID,
                    'fieldName' => 'Content',
                    'suggestedContent' => '<p>Updated JSON content</p><script>alert(1)</script>'
                        . '<style>.evil{display:none}</style>',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(2, $payload['appliedCount'] ?? null);
        $this->assertSame(0, $payload['skippedCount'] ?? null);
        $this->assertTrue($payload['reloadRequired'] ?? false);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecord(SiteTree::class, $page->ID);
        $this->assertSame('Updated from JSON body', $draftPage->Title);
        $this->assertSame('<p>Updated JSON content</p>', $draftPage->Content);
    }

    /**
     * Confirms element apply sanitises HTML and skips deleted or foreign blocks without touching valid ones.
     */
    public function testApplyEndpointSanitisesElementHtmlAndSkipsDeletedOrForeignTargets(): void
    {
        $page = $this->createElementalPage('Elemental apply page', [
            '<p>Current block</p>',
            '<p>Deleted block</p>',
        ]);
        $currentElement = $page->ElementalArea()->Elements()->sort('ID')->first();
        $deletedElement = $page->ElementalArea()->Elements()->sort('ID')->last();
        $deletedTargetKey = sprintf('element:%d:html', $deletedElement->ID);
        $deletedElement->delete();

        $foreignPage = $this->createElementalPage('Foreign page', ['<p>Foreign block</p>']);
        $foreignElement = $foreignPage->ElementalArea()->Elements()->first();
        $foreignTargetKey = sprintf('element:%d:html', $foreignElement->ID);

        $response = $this->applySuggestions($page, [
            [
                'apply' => '1',
                'targetKey' => sprintf('element:%d:html', $currentElement->ID),
                'targetType' => 'element_html',
                'targetId' => $currentElement->ID,
                'fieldName' => 'HTML',
                'suggestedContent' => '<p onclick="alert(1)">Updated <strong onmouseover="alert(2)">'
                    . 'current block</strong></p>',
            ],
            [
                'apply' => '1',
                'targetKey' => $deletedTargetKey,
                'targetType' => 'element_html',
                'targetId' => $deletedElement->ID,
                'fieldName' => 'HTML',
                'suggestedContent' => '<p>Updated deleted block</p>',
            ],
            [
                'apply' => '1',
                'targetKey' => $foreignTargetKey,
                'targetType' => 'element_html',
                'targetId' => $foreignElement->ID,
                'fieldName' => 'HTML',
                'suggestedContent' => '<p>Updated foreign block</p>',
            ],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $payload['appliedCount'] ?? null);
        $this->assertSame(2, $payload['skippedCount'] ?? null);
        $this->assertTrue($payload['reloadRequired'] ?? false);

        /** @var ElementContent $updatedCurrentElement */
        $updatedCurrentElement = $this->getDraftRecord(ElementContent::class, $currentElement->ID);
        $this->assertSame('<p>Updated <strong>current block</strong></p>', $updatedCurrentElement->HTML);

        /** @var ElementContent $unchangedForeignElement */
        $unchangedForeignElement = $this->getDraftRecord(ElementContent::class, $foreignElement->ID);
        $this->assertSame('<p>Foreign block</p>', $unchangedForeignElement->HTML);

        $reasons = array_map(
            static fn(array $record): ?string => $record['context']['reason'] ?? null,
            $this->logger->records
        );
        $this->assertContains('deleted-target', $reasons);
        $this->assertContains('foreign-target', $reasons);
    }

    /**
     * Confirms apply requests are skipped when the payload metadata no longer matches the target.
     */
    public function testApplyEndpointSkipsMetadataMismatchesFromJsonPayload(): void
    {
        $page = $this->createPage('Mismatch page', '<p>Draft content</p>');

        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [[
                'apply' => true,
                'targetKey' => 'page:title',
                'targetType' => 'page_content',
                'targetId' => $page->ID + 99,
                'fieldName' => 'Content',
                'suggestedContent' => 'Should be skipped',
            ]],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(0, $payload['appliedCount'] ?? null);
        $this->assertSame(1, $payload['skippedCount'] ?? null);
        $this->assertFalse($payload['reloadRequired'] ?? true);

        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecord(SiteTree::class, $page->ID);
        $this->assertSame('Mismatch page', $draftPage->Title);
        $this->assertSame('<p>Draft content</p>', $draftPage->Content);

        $reasons = array_map(
            static fn(array $record): ?string => $record['context']['reason'] ?? null,
            $this->logger->records
        );
        $this->assertContains('target-metadata-mismatch', $reasons);
    }

    /**
     * Confirms restricted pages cannot accept apply requests.
     */
    public function testApplyEndpointRejectsRestrictedPage(): void
    {
        $page = RestrictedBrandVoicePage::create([
            'Title' => 'Restricted apply page',
            'Content' => '<p>Draft content</p>',
        ]);
        $page->write();

        $response = $this->applySuggestions($page, [[
            'apply' => '1',
            'targetKey' => 'page:title',
            'suggestedContent' => 'Nope',
        ]]);

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Access denied', $payload['error'] ?? null);
    }

    /**
     * Confirms stale security tokens are rejected for apply requests.
     */
    public function testApplyEndpointRejectsStaleSecurityToken(): void
    {
        $page = $this->createPage('Apply page', '<p>Draft content</p>');
        $this->session()->set(SecurityToken::inst()->getName(), 'fresh-security-token');

        $response = $this->applySuggestions($page, [[
            'apply' => '1',
            'targetKey' => 'page:title',
            'suggestedContent' => 'Should not apply',
        ]], 'stale-security-token');

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Session timed out, please refresh and try again.', $payload['error'] ?? null);
    }

    /**
     * Confirms malformed JSON apply payloads return a validation error.
     */
    public function testApplyEndpointRejectsInvalidJsonPayload(): void
    {
        $page = $this->createPage('Invalid payload page', '<p>Draft content</p>');

        $response = $this->applySuggestionsJson($page, '{"invalid":true}');

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Invalid apply request payload', $payload['error'] ?? null);
    }

    /**
     * Creates a simple draft page fixture for controller tests.
     */
    private function createPage(string $title, string $content): SiteTree
    {
        $page = SiteTree::create([
            'Title' => $title,
            'Content' => $content,
        ]);
        $page->write();
        return $page;
    }

    /**
     * Creates a draft elemental page populated with the supplied HTML blocks.
     */
    private function createElementalPage(string $title, array $blocks): CETestElementalPage
    {
        return Versioned::withVersionedMode(function () use ($title, $blocks): CETestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);

            $page = CETestElementalPage::create([
                'Title' => $title,
            ]);
            $page->write();

            foreach ($blocks as $html) {
                $page->ElementalArea()->Elements()->add(ElementContent::create([
                    'HTML' => $html,
                ]));
            }
            return DataObject::get(CETestElementalPage::class)->byID($page->ID);
        });
    }

    /**
     * Posts a form-encoded apply request for the supplied page suggestions.
     */
    private function applySuggestions(SiteTree $page, array $suggestions, ?string $securityToken = null)
    {
        $postVars = [
            'suggestions' => $suggestions,
        ];
        $headers = [];

        if ($securityToken !== '') {
            $tokenValue = $securityToken ?? SecurityToken::inst()->getValue();
            $postVars[SecurityToken::inst()->getName()] = $tokenValue;
            $headers['X-SecurityID'] = $tokenValue;
        }
        return $this->post(
            '/admin/ai-brand-voice/apply/' . $page->ID . '?fqcn=' . rawurlencode($page->ClassName),
            $postVars,
            $headers
        );
    }

    /**
     * Posts a JSON apply request for the supplied page suggestions.
     */
    private function applySuggestionsJson(SiteTree $page, array|string $payload, ?string $securityToken = null)
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($securityToken !== '') {
            $tokenValue = $securityToken ?? SecurityToken::inst()->getValue();
            $headers['X-SecurityID'] = $tokenValue;
        }

        $body = is_string($payload) ? $payload : json_encode($payload);
        return Director::test(
            '/admin/ai-brand-voice/apply/' . $page->ID . '?fqcn=' . rawurlencode($page->ClassName),
            [],
            $this->session(),
            'POST',
            $body,
            $headers
        );
    }

    /**
     * Loads a record from the draft stage for post-apply assertions.
     */
    private function getDraftRecord(string $className, int $id): ?DataObject
    {
        return Versioned::withVersionedMode(function () use ($className, $id): ?DataObject {
            Versioned::set_stage(Versioned::DRAFT);
            return DataObject::get($className)->byID($id);
        });
    }
}
