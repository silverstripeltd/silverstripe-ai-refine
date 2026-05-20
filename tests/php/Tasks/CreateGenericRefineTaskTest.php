<?php

namespace SilverstripeLtd\AiRefine\Tests\Tasks;

use SilverstripeLtd\AiRefine\Tasks\CreateGenericRefineTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers the generic refine build task.
 */
class CreateGenericRefineTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        SiteConfig::class,
    ];

    /**
     * Clears the configured refine after each task test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms the task writes the generic definition into Site Settings.
     */
    public function testTaskCreatesGenericRefineInSiteSettings(): void
    {
        [$exitCode, $output] = $this->runTask();
        $siteConfig = SiteConfig::current_site_config();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString("Running task 'Create writing style and tone rules'", $output);
        $this->assertStringContainsString('Created writing style and tone rules in Site Settings.', $output);
        $this->assertStringContainsString('bold, confident, and composed', $siteConfig->RefineDefinition);
        $this->assertStringContainsString(
            'government agency and a financial organisation',
            $siteConfig->RefineDefinition
        );
        $this->assertStringContainsString('leading the industry', $siteConfig->RefineDefinition);
    }

    /**
     * Confirms the task becomes a no-op once the generic definition is already present.
     */
    public function testTaskSkipsWhenGenericRefineIsAlreadyConfigured(): void
    {
        $this->runTask();
        [$exitCode, $output] = $this->runTask();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Generic writing style and tone rules is already configured in Site Settings.', $output);
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

        $task = new CreateGenericRefineTask();
        $exitCode = $task->run($input, $output);
        return [$exitCode, $buffer->fetch()];
    }
}
