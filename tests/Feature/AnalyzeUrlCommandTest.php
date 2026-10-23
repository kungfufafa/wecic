<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `analyze:urls` documents the class URL parameters; its rules must match
 * what the class endpoints accept (any positive id, not only four digits).
 */
class AnalyzeUrlCommandTest extends TestCase
{
    public function test_the_parameter_rules_match_the_class_endpoint_validation(): void
    {
        $this->artisan('analyze:urls')
            ->expectsOutputToContain('Range: positive integer, up to 10 digits')
            ->expectsOutputToContain('Range: positive integer')
            ->doesntExpectOutputToContain('Range: 1000-9999')
            ->doesntExpectOutputToContain('Range: 8000-9999')
            ->doesntExpectOutputToContain('/^\d{4}$/')
            ->assertSuccessful();
    }
}
