<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Middleware\PortalSessionMiddleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Errors of the API (`/api/*`, every version) are always JSON in the API's
 * envelope (`status: 'error'`, `message`, and `errors` for validation),
 * also for a client that sends no `Accept: application/json` (a mobile
 * app, curl) and for the errors no controller answers: an unknown route, a
 * method the route does not take, a spent rate limit, a CSRF mismatch, a
 * body over the server's size limit, an uncaught exception (whose details
 * only debug mode shows).
 *
 * The framework's English defaults ("Too Many Attempts.", "Server Error")
 * become the words the web app already shows for that status
 * (resources/js/lib/http.js), so a mobile app shows the same; a message app
 * code gave its exception is kept.
 *
 * A navigation to a route the browser opens as a page (a download link)
 * keeps its HTML error pages (PortalSessionMiddleware::isPageNavigation()).
 */
class ApiExceptionRenderer
{
    /**
     * The framework's English defaults, as resources/js/lib/http.js lists
     * them, plus the ones only a non-browser client meets.
     */
    private const FRAMEWORK_DEFAULT_MESSAGE = '/^(?:Too Many Attempts\.|Server Error|Service Unavailable|CSRF token mismatch\.|Unauthenticated\.|Unauthorized|Forbidden|This action is unauthorized\.|Not Found\.?|No query results for model .*|The route .* could not be found\.|Method Not Allowed|The \S+ method is not supported for route .*|Page Expired|The POST data is too large\.|Bad Request\.?)$/i';

    /**
     * The handler's `shouldRenderJsonWhen`: what asks for JSON gets JSON,
     * and so does everything under `/api`.
     */
    public static function rendersJson(Request $request): bool
    {
        return $request->expectsJson() || self::isApiRequest($request);
    }

    public static function isApiRequest(Request $request): bool
    {
        return $request->is('api', 'api/*') && ! PortalSessionMiddleware::isPageNavigation($request);
    }

    /**
     * The handler's `respond`: puts an API error the framework rendered
     * into the envelope. A response that already has one (an ApiFormRequest's
     * 422, a controller's error) or that app code built itself
     * (HttpResponseException) is left alone.
     */
    public static function respond(Response $response, Throwable $e, Request $request): Response
    {
        if (! $response instanceof JsonResponse
            || $response->getStatusCode() < 400
            || $e instanceof HttpResponseException
            || ! self::isApiRequest($request)) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || array_key_exists('status', $data)) {
            return $response;
        }

        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';

        if ($message === '' || preg_match(self::FRAMEWORK_DEFAULT_MESSAGE, $message) === 1) {
            $message = self::statusMessage($response->getStatusCode(), $e, $request);
        }

        return $response->setData(['status' => 'error', 'message' => $message] + $data);
    }

    /**
     * The words for a status, as the web app words it.
     */
    private static function statusMessage(int $status, Throwable $e, Request $request): string
    {
        return match (true) {
            $status === 429 => self::throttledMessage($e),
            $status === 419 => 'Halaman sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.',
            $status === 401 => 'Sesi kampus sudah berakhir. Silakan masuk lagi.',
            $status === 403 => 'Kamu tidak punya akses ke data ini.',
            $status === 404 && $request->route() === null => 'Endpoint tidak ditemukan.',
            $status === 404 => 'Data yang diminta tidak ditemukan.',
            $status === 405 => sprintf('Metode %s tidak didukung endpoint ini.', $request->method()),
            $status === 413 => 'Ukuran data yang dikirim melebihi batas server.',
            $status >= 500 => 'Server sedang bermasalah. Coba lagi sebentar lagi.',
            default => 'Permintaan tidak bisa diproses. Coba lagi sebentar lagi.',
        };
    }

    private static function throttledMessage(Throwable $e): string
    {
        $retryAfter = $e instanceof HttpExceptionInterface ? ($e->getHeaders()['Retry-After'] ?? null) : null;

        if (! is_numeric($retryAfter) || (int) $retryAfter <= 0) {
            return 'Terlalu sering mencoba. Tunggu sebentar lalu coba lagi.';
        }

        $seconds = (int) $retryAfter;

        return $seconds >= 60
            ? sprintf('Terlalu sering mencoba. Coba lagi dalam %d menit.', (int) ceil($seconds / 60))
            : sprintf('Terlalu sering mencoba. Coba lagi dalam %d detik.', $seconds);
    }
}
