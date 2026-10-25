<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * What `composer run check` wires together, and that CI checks the same
 * things (README, "Menjalankan tes, Pint, dan Larastan").
 */
class QualityGateTest extends TestCase
{
    public function test_composer_check_runs_pint_the_node_tests_larastan_and_the_php_tests(): void
    {
        $scripts = $this->composerScripts();

        $this->assertSame([
            'Composer\\Config::disableProcessTimeout',
            'vendor/bin/pint --test',
            'npm run test:js',
            '@analyse',
            '@test',
        ], $scripts['check']);

        $this->assertStringStartsWith('vendor/bin/phpstan analyse ', $scripts['analyse']);
        $this->assertSame(['@php artisan config:clear --ansi', '@php artisan test'], $scripts['test']);
    }

    public function test_larastan_reports_only_errors_missing_from_the_baseline(): void
    {
        $config = file_get_contents(base_path('phpstan.neon'));
        $baseline = base_path('phpstan-baseline.neon');

        $this->assertStringContainsString("\n    - phpstan-baseline.neon\n", $config);
        $this->assertFileExists($baseline);
        $this->assertStringStartsWith("parameters:\n\tignoreErrors:\n", file_get_contents($baseline));

        $this->assertStringEndsWith(' --generate-baseline phpstan-baseline.neon', $this->composerScripts()['analyse:baseline']);
    }

    public function test_ci_runs_every_check_composer_check_runs(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

        foreach (['vendor/bin/pint --test', 'npm run test:js', 'composer run analyse', 'php artisan test'] as $command) {
            $this->assertStringContainsString("run: {$command}\n", $workflow, $command);
        }
    }

    public function test_composer_requires_the_php_version_the_readme_and_ci_install(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('^8.4', $composer['require']['php']);
        $this->assertStringContainsString("php-version: '8.4'", file_get_contents(base_path('.github/workflows/tests.yml')));
        $this->assertStringContainsString('PHP 8.4', file_get_contents(base_path('README.md')));
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function composerScripts(): array
    {
        return json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['scripts'];
    }
}
