<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

class SentryIntegrationTest extends TestCase
{
    /** @var list<Event> */
    private array $sentEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentEvents = [];
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub(new Hub);

        parent::tearDown();
    }

    public function test_tests_never_have_a_sentry_dsn(): void
    {
        $this->assertNull(config('sentry.dsn'));
    }

    public function test_reported_exceptions_are_sent_to_sentry(): void
    {
        $this->bindFakeSentryClient();

        report(new RuntimeException('Sentry wiring check'));

        $this->assertCount(1, $this->sentEvents);
        $this->assertSame('Sentry wiring check', $this->sentEvents[0]->getExceptions()[0]->getValue());
    }

    public function test_exceptions_laravel_does_not_report_are_not_sent(): void
    {
        $this->bindFakeSentryClient();

        $this->get('/a-page-that-does-not-exist')->assertNotFound();

        $this->assertSame([], $this->sentEvents);
    }

    /** Points the Sentry SDK at an in-memory transport that records each event. */
    private function bindFakeSentryClient(): void
    {
        $transport = new class($this->sentEvents) implements TransportInterface
        {
            /** @param  list<Event>  $events */
            public function __construct(private array &$events) {}

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $client = ClientBuilder::create(['dsn' => 'https://public@o1.ingest.sentry.io/2'])
            ->setTransport($transport)
            ->getClient();

        SentrySdk::setCurrentHub(new Hub($client));
    }
}
