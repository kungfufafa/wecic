<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

/**
 * `scrape:test` logs in to the portal for real, so it must never take the
 * password on the command line nor leave the session (and its keep-alive)
 * behind.
 */
class ScrapeTestCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_password_is_asked_for_and_the_session_is_logged_out_afterwards(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSession')->once()->with('20990000009', 'rahasia')->andReturn(['session_token' => 'token-scrape-test']);
            $mock->shouldReceive('destroySession')->once()->with('token-scrape-test')->andReturnTrue();
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')->twice()->andReturn(['course_info' => ['name' => 'Basis Data']]);
        }));

        $this->artisan('scrape:test', ['--username' => '20990000009'])
            ->expectsQuestion('Portal password', 'rahasia')
            ->assertSuccessful();
    }

    public function test_the_session_is_logged_out_even_when_scraping_fails(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSession')->once()->andReturn(['session_token' => 'token-scrape-test']);
            $mock->shouldReceive('destroySession')->once()->with('token-scrape-test')->andReturnTrue();
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')->andThrow(new RuntimeException('Halaman kelas tidak terbaca'));
        }));

        $this->artisan('scrape:test', ['--username' => '20990000009'])
            ->expectsQuestion('Portal password', 'rahasia')
            ->assertSuccessful();
    }

    public function test_nothing_logs_in_without_a_username_or_password(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createSession');
        }));
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class));

        $this->artisan('scrape:test')->assertSuccessful();
        $this->artisan('scrape:test', ['--username' => '20990000009'])
            ->expectsQuestion('Portal password', '')
            ->assertSuccessful();
    }

    public function test_the_password_cannot_be_passed_as_an_option(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->artisan('scrape:test', ['--username' => '20990000009', '--password' => 'rahasia'])->run();
    }
}
