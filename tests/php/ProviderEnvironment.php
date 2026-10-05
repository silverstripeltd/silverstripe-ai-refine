<?php

namespace SilverstripeLtd\AiRefine\Tests;

use SilverStripe\Core\Environment;
use SilverStripe\Dev\TestOnly;

/**
 * Clears and restores the provider environment variables a test may depend on.
 */
class ProviderEnvironment implements TestOnly
{
    private const SUFFIXES = [
        'PROVIDER',
        'API_KEY',
        'MODEL',
        'MAX_TOKENS',
        'REWRITE_MAX_TOKENS',
        'REQUEST_TIMEOUT',
        'TEMPERATURE',
        'THINKING_LEVEL',
    ];

    private const PREFIXES = ['AI_REFINE_', 'AI_'];

    /**
     * Unsets every refine and shared provider variable and returns the previous values.
     *
     * @return array<string, mixed>
     */
    public static function clear(): array
    {
        $original = [];
        foreach (self::PREFIXES as $prefix) {
            foreach (self::SUFFIXES as $suffix) {
                $original[$prefix . $suffix] = Environment::getEnv($prefix . $suffix);
                Environment::setEnv($prefix . $suffix, null);
            }
        }
        return $original;
    }

    /**
     * Restores the values returned by clear().
     *
     * @param array<string, mixed> $original
     */
    public static function restore(array $original): void
    {
        foreach ($original as $name => $value) {
            Environment::setEnv($name, $value === false ? null : $value);
        }
    }
}
