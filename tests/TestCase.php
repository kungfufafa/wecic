<?php

namespace Tests;

use App\Services\PortalHttpClientOptions;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    /**
     * How every failure about a portal request the test did not mock starts.
     */
    private const UNMOCKED_PORTAL_REQUEST = 'A portal request the test did not mock was sent';

    /**
     * Portal requests the test did not mock, as "METHOD url".
     *
     * @var list<string>
     */
    private array $unmockedPortalRequests = [];

    /**
     * Set by answerPortalRequestsAsUnreachable().
     */
    private bool $portalUnreachable = false;

    /**
     * The portal guard is bound before the providers boot, so no portal
     * client is ever built without it.
     */
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->guardPortalRequests($app);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->keepOutboundRequestsInsideTheTest();
    }

    /**
     * Every portal client sends through the handler bound here
     * (PortalHttpClientOptions::HANDLER_BINDING), whether the container or
     * the test built it: a request the test did not mock fails the test
     * instead of leaving it. A client a test gives its own handler
     * (MockHandler) is not affected.
     */
    protected function guardPortalRequests(Application $app): void
    {
        $app->bind(PortalHttpClientOptions::HANDLER_BINDING, fn (): HandlerStack => HandlerStack::create(
            fn (RequestInterface $request, array $options): PromiseInterface => $this->answerUnmockedPortalRequest($request)
        ));
    }

    /**
     * For a test about an unreachable portal: requests the test did not mock
     * get the connection error of a host that cannot be reached, instead of
     * failing the test.
     */
    protected function answerPortalRequestsAsUnreachable(): void
    {
        $this->portalUnreachable = true;
    }

    /**
     * No test reaches the network: portal requests go through the guard
     * (guardPortalRequests()), and an HTTP client call the test did not fake
     * (Gemini) throws. A test case that builds its application itself
     * (its own createApplication()) is guarded from here on.
     *
     * Code under test may swallow the failure an unmocked portal request
     * raised: the request still fails the test, once, when the application
     * is torn down.
     */
    private function keepOutboundRequestsInsideTheTest(): void
    {
        Http::preventStrayRequests();

        if (! $this->app->bound(PortalHttpClientOptions::HANDLER_BINDING)) {
            $this->guardPortalRequests($this->app);
        }

        $this->beforeApplicationDestroyed(function (): void {
            $requests = $this->unmockedPortalRequests;
            $this->unmockedPortalRequests = [];

            if ($requests === [] || ($this->status()->isFailure() && str_starts_with($this->status()->message(), self::UNMOCKED_PORTAL_REQUEST))) {
                return;
            }

            $this->fail($this->unmockedPortalRequestMessage($requests));
        });
    }

    private function answerUnmockedPortalRequest(RequestInterface $request): PromiseInterface
    {
        $uri = $request->getUri()->withUserInfo('');

        if ($this->portalUnreachable) {
            return Create::rejectionFor(new ConnectException("cURL error 6: Could not resolve host: {$uri->getHost()} (the test made the portal unreachable).", $request));
        }

        $this->unmockedPortalRequests[] = $request->getMethod().' '.$uri;

        return Create::rejectionFor(new AssertionFailedError($this->unmockedPortalRequestMessage($this->unmockedPortalRequests)));
    }

    /**
     * @param  list<string>  $requests
     */
    private function unmockedPortalRequestMessage(array $requests): string
    {
        return self::UNMOCKED_PORTAL_REQUEST.': '.implode(', ', array_unique($requests)).'. Give the client a MockHandler, mock the service, or call answerPortalRequestsAsUnreachable().';
    }
}
