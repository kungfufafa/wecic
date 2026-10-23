<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use Psr\Http\Message\RequestInterface;

/**
 * Reads portal pages with a campus session for `portal:capture-fixtures`,
 * and can do nothing else: any method but GET is refused twice, before a
 * request is built (fetch()) and in the client's own handler stack, which
 * also covers every redirect it follows. Pages go through the same session
 * cookies, headers and response checks as every other portal client.
 */
class PortalReadOnlyFetcher extends PortalScrapingService
{
    public const GUARD_NAME = 'portal_read_only';

    public function __construct(PortalSessionManager $sessionManager)
    {
        parent::__construct($sessionManager);

        $handler = $this->client->getConfig('handler');

        if ($handler instanceof HandlerStack) {
            $handler->push(self::readOnlyMiddleware(), self::GUARD_NAME);

            return;
        }

        $stack = new HandlerStack($handler);
        $stack->push(self::readOnlyMiddleware(), self::GUARD_NAME);
        $this->client = new Client([...$this->client->getConfig(), 'handler' => $stack]);
    }

    /**
     * Read one portal page.
     *
     * @param  string  $method  The method the endpoint registry gives the page; anything but GET is refused.
     *
     * @throws LogicException When the method is not GET; nothing is sent.
     */
    public function fetch(string $url, string $method = 'GET'): string
    {
        return $this->request($method, $url);
    }

    /**
     * @throws LogicException When the method is not GET; nothing is sent.
     */
    public static function assertReadOnly(string $method): void
    {
        $method = strtoupper(trim($method));

        if ($method !== 'GET') {
            throw new LogicException(sprintf('Refused a %s request: portal fixtures are captured with GET requests only.', $method !== '' ? $method : 'request without a method'));
        }
    }

    /**
     * Guzzle middleware refusing every request that is not a GET.
     *
     * @return callable(callable(RequestInterface, array<string, mixed>): PromiseInterface): callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public static function readOnlyMiddleware(): callable
    {
        return static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            self::assertReadOnly($request->getMethod());

            return $handler($request, $options);
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function request(string $method, string $url, array $options = []): string
    {
        self::assertReadOnly($method);

        return parent::request($method, $url, $options);
    }
}
