<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Tasks;

use SilverstripeLtd\AiBrandVoice\Tasks\CreateGenericBrandVoiceTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers the generic brand voice build task.
 */
class CreateGenericBrandVoiceTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        SiteConfig::class,
    ];

    /**
     * Clears the configured brand voice after each task test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms the task writes the generic definition into Site Settings.
     */
    public function testTaskCreatesGenericBrandVoiceInSiteSettings(): void
    {
        [$exitCode, $output] = $this->runTask();
        $siteConfig = SiteConfig::current_site_config();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString("Running task 'Create generic brand voice'", $output);
        $this->assertStringContainsString('Created generic brand voice in Site Settings.', $output);
        $this->assertStringContainsString('bold, confident, and composed', $siteConfig->BrandVoiceDefinition);
        $this->assertStringContainsString(
            'government agency and a financial organisation',
            $siteConfig->BrandVoiceDefinition
        );
        $this->assertStringContainsString('leading the industry', $siteConfig->BrandVoiceDefinition);
    }

    /**
     * Confirms the task becomes a no-op once the generic definition is already present.
     */
    public function testTaskSkipsWhenGenericBrandVoiceIsAlreadyConfigured(): void
    {
        $this->runTask();
        [$exitCode, $output] = $this->runTask();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Generic brand voice is already configured in Site Settings.', $output);
    }

    /**
     * Runs the build task and returns its exit code and buffered console output.
     */
    private function runTask(): array
    {
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $task = new CreateGenericBrandVoiceTask();
        $exitCode = $task->run($input, $output);
        return [$exitCode, $buffer->fetch()];
    }
}
