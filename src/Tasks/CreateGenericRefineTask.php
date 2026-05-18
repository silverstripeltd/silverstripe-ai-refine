<?php

namespace SilverstripeLtd\AiRefine\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Seeds Site Settings with a reusable generic refine definition.
 */
class CreateGenericRefineTask extends BuildTask
{
    protected static string $commandName = 'create-generic-brand-voice';

    protected string $title = '';

    protected static string $description = 'Create a generic refine definition in Site Settings.';

    private const GENERIC_REFINE_DEFINITION = <<<'TEXT'
Our writing style and tone is bold, confident, and composed.
We sit somewhere between a government agency and a financial organisation
that sees itself as leading the industry.
We communicate with authority, stability, and calm control,
while still sounding useful and human.

Tone:
- Clear, decisive, and reassuring
- Professional and disciplined without sounding cold
- Ambitious and forward-looking without slipping into hype

Audience:
- Customers, stakeholders, and the wider public who expect clarity, trust, and leadership

Style rules:
- Lead with the outcome and state it plainly
- Use precise, accountable language and avoid fluff
- Emphasise trust, resilience, good governance, and industry leadership
- Keep sentences concise and easy to scan
- Sound confident and informed, never casual or apologetic
TEXT;

    /**
     * Returns the task title shown when the build task runs.
     */
    public function getTitle(): string
    {
        return 'Create generic refine';
    }

    /**
     * Writes the generic refine definition into Site Settings when needed.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $siteConfig = SiteConfig::current_site_config();
        $existingDefinition = $this->normaliseRefineDefinition(
            $siteConfig,
            (string) $siteConfig->RefineDefinition
        );
        $genericDefinition = $this->normaliseRefineDefinition(
            $siteConfig,
            self::GENERIC_REFINE_DEFINITION
        );
        if ($existingDefinition === $genericDefinition) {
            $output->writeln('Generic refine is already configured in Site Settings.');
            return Command::SUCCESS;
        }
        $siteConfig->RefineDefinition = $genericDefinition;
        $siteConfig->write();
        $message = $existingDefinition === ''
            ? 'Created generic refine in Site Settings.'
            : 'Updated generic refine in Site Settings.';
        $output->writeln($message);
        return Command::SUCCESS;
    }

    /**
     * Normalises refine text using the SiteConfig helper when it is available.
     */
    private function normaliseRefineDefinition(SiteConfig $siteConfig, string $value): string
    {
        if ($siteConfig->hasMethod('normaliseRefineDefinition')) {
            return (string) $siteConfig->normaliseRefineDefinition($value);
        }
        return trim($value);
    }
}
