<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalScrapingException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class SipandaiSubmissionService extends PortalScrapingService
{
    /**
     * Upload assignment file to SIPANDAI portal.
     */
    public function submitAssignment(
        string $sessionToken,
        int $courseId,
        int $discussionId,
        int $meetingNumber,
        UploadedFile $attachment,
        array $overrides = []
    ): array {
        $this->setSession($sessionToken);

        $targetUrl = $this->buildAssignmentUrl($courseId, $discussionId, $meetingNumber);
        $this->logScraping('Uploading SIPANDAI assignment', [
            'session_token' => $sessionToken,
            'course_id' => $courseId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ]);

        $formCrawler = $this->resolveFormForAssignment($targetUrl);
        $formMeta = $this->extractFormMetadata($formCrawler);

        if ($formMeta['file_field'] === null) {
            throw PortalScrapingException::elementNotFound('input[type="file"]', 'assignment upload form');
        }

        $payload = $this->mergeOverrides($formMeta['fields'], array_merge([
            'pertemuan' => (string) $meetingNumber,
        ], $overrides));

        $actionUrl = $this->resolveActionUrl($formMeta['action'], $targetUrl);

        $multipart = $this->buildMultipartPayload($payload, $formMeta['file_field'], $attachment);

        $response = $this->dispatchRequest(
            strtoupper($formMeta['method'] ?? 'POST'),
            $actionUrl,
            [
                RequestOptions::MULTIPART => $multipart,
            ],
            $targetUrl
        );

        $result = $this->buildResultPayload((string) $response->getBody(), $actionUrl, [
            'course_id' => $courseId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ]);

        $result['data']['redirect_history'] = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);
        $result['data']['sent_fields'] = array_keys($payload);
        $result['data']['file_field'] = $formMeta['file_field'];

        return $result;
    }

    /**
     * Submit attendance confirmation on SIPANDAI portal.
     */
    public function submitAttendance(
        string $sessionToken,
        int $courseId,
        int $discussionId,
        ?int $meetingNumber = null,
        array $overrides = []
    ): array {
        $this->setSession($sessionToken);

        $targetUrl = $this->buildAttendanceUrl($courseId, $discussionId, $meetingNumber);
        $this->logScraping('Submitting SIPANDAI attendance', [
            'session_token' => $sessionToken,
            'course_id' => $courseId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ]);

        $crawler = $this->createCrawler($this->get($targetUrl));

        if ($crawler->filter('form')->count() === 0) {
            return $this->buildResultPayload($crawler->html(), $targetUrl, [
                'course_id' => $courseId,
                'discussion_id' => $discussionId,
                'meeting_number' => $meetingNumber,
                'strategy' => 'page_visit',
            ]);
        }

        $formCrawler = $crawler->filter('form')->first();
        $formMeta = $this->extractFormMetadata($formCrawler);
        $payload = $this->mergeOverrides($formMeta['fields'], array_merge(
            $meetingNumber !== null ? ['pertemuan' => (string) $meetingNumber] : [],
            $overrides
        ));

        $actionUrl = $this->resolveActionUrl($formMeta['action'], $targetUrl);

        $options = [];
        if ($formMeta['enctype'] === 'multipart/form-data' && $formMeta['file_field'] !== null) {
            throw PortalScrapingException::invalidHtmlStructure('attendance form contains file input unexpectedly');
        }

        if (strtoupper($formMeta['method']) === 'GET') {
            $finalUrl = $this->appendQuery($actionUrl, $payload);
            $response = $this->dispatchRequest('GET', $finalUrl, [], $targetUrl);
        } else {
            $options[RequestOptions::FORM_PARAMS] = $payload;
            $response = $this->dispatchRequest('POST', $actionUrl, $options, $targetUrl);
        }

        $result = $this->buildResultPayload((string) $response->getBody(), $actionUrl, [
            'course_id' => $courseId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ]);

        $result['data']['redirect_history'] = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);
        $result['data']['sent_fields'] = array_keys($payload);

        return $result;
    }

    /**
     * Delete uploaded assignment entry.
     */
    public function deleteAssignment(
        string $sessionToken,
        int $submissionId,
        int $discussionId,
        int $meetingNumber
    ): array {
        $this->setSession($sessionToken);

        $targetUrl = $this->buildAssignmentDeleteUrl($submissionId, $discussionId, $meetingNumber);
        $this->logScraping('Deleting SIPANDAI assignment submission', [
            'session_token' => $sessionToken,
            'submission_id' => $submissionId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ]);

        $response = $this->dispatchRequest('GET', $targetUrl, [], $targetUrl);

        return $this->buildResultPayload((string) $response->getBody(), $targetUrl, [
            'submission_id' => $submissionId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ]);
    }

    /**
     * Fetch assignment upload form and return crawler instance.
     */
    private function resolveFormForAssignment(string $targetUrl): Crawler
    {
        $html = $this->get($targetUrl);
        $crawler = $this->createCrawler($html);

        $forms = $crawler->filter('form');
        if ($forms->count() === 0) {
            throw PortalScrapingException::elementNotFound('form', 'assignment upload page');
        }

        foreach ($forms as $node) {
            $formCrawler = new Crawler($node);
            if ($formCrawler->filter('input[type="file"]')->count() > 0) {
                return $formCrawler;
            }
        }

        return $forms->first();
    }

    /**
     * Build assignment URL with query parameters.
     */
    private function buildAssignmentUrl(int $courseId, int $discussionId, int $meetingNumber): string
    {
        return PortalEndpointRegistry::url('assignment_form', [
            'courses_id' => $courseId,
            'id' => $discussionId,
            'pertemuan' => $meetingNumber,
        ]);
    }

    /**
     * Build attendance URL with query parameters.
     */
    private function buildAttendanceUrl(int $courseId, int $discussionId, ?int $meetingNumber = null): string
    {
        $params = [
            'course_id' => $courseId,
            'id' => $discussionId,
        ];

        if ($meetingNumber !== null) {
            $params['pertemuan'] = $meetingNumber;
        }

        return PortalEndpointRegistry::url('qr_attendance_page', $params);
    }

    private function buildAssignmentDeleteUrl(int $submissionId, int $discussionId, int $meetingNumber): string
    {
        return PortalEndpointRegistry::url('assignment_delete', [
            'id' => $submissionId,
            'idx' => $discussionId,
            'pertemuan' => $meetingNumber,
        ]);
    }

    /**
     * Extract form metadata for submission.
     */
    private function extractFormMetadata(Crawler $form): array
    {
        $fields = [];
        $fileField = null;

        $form->filter('input')->each(function (Crawler $input) use (&$fields, &$fileField): void {
            $name = $input->attr('name');
            if (! $name) {
                return;
            }

            $type = strtolower($input->attr('type') ?? 'text');

            if ($type === 'file') {
                $fileField = $name;

                return;
            }

            if ($type === 'checkbox' || $type === 'radio') {
                if ($input->attr('checked') === null) {
                    return;
                }
            }

            $fields[$name] = $input->attr('value') ?? '';
        });

        $form->filter('textarea')->each(function (Crawler $textarea) use (&$fields): void {
            $name = $textarea->attr('name');
            if (! $name) {
                return;
            }

            $fields[$name] = trim($textarea->text());
        });

        $form->filter('select')->each(function (Crawler $select) use (&$fields): void {
            $name = $select->attr('name');
            if (! $name) {
                return;
            }

            $selected = $select->filter('option[selected]');
            if ($selected->count() === 0) {
                $selected = $select->filter('option')->first();
            }

            $fields[$name] = $selected->count() > 0 ? ($selected->attr('value') ?? trim($selected->text())) : '';
        });

        $form->filter('button[name]')->each(function (Crawler $button) use (&$fields): void {
            $name = $button->attr('name');
            if (! $name) {
                return;
            }

            $fields[$name] = $button->attr('value') ?? trim($button->text());
        });

        return [
            'action' => $form->attr('action') ?? '',
            'method' => strtolower($form->attr('method') ?? 'post'),
            'enctype' => strtolower($form->attr('enctype') ?? 'application/x-www-form-urlencoded'),
            'file_field' => $fileField,
            'fields' => $fields,
        ];
    }

    /**
     * Merge default fields with overrides, normalising to string values.
     */
    private function mergeOverrides(array $fields, array $overrides): array
    {
        $merged = array_merge($fields, $overrides);

        return array_map(static function ($value) {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_array($value)) {
                return json_encode($value, JSON_THROW_ON_ERROR);
            }

            return (string) $value;
        }, $merged);
    }

    /**
     * Append query string to base URL.
     */
    private function appendQuery(string $baseUrl, $query, bool $raw = false): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        if ($raw) {
            return rtrim($baseUrl, '&').$separator.$query;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return rtrim($baseUrl, '&').$separator.$queryString;
    }

    /**
     * Resolve form action URL against reference URL.
     */
    private function resolveActionUrl(string $action, string $referenceUrl): string
    {
        if ($action === '' || $action === '#') {
            return $referenceUrl;
        }

        if (Str::startsWith($action, ['http://', 'https://'])) {
            return $action;
        }

        if (Str::startsWith($action, '?')) {
            return Str::before($referenceUrl, '?').$action;
        }

        if (Str::startsWith($action, '/')) {
            return rtrim(config('portal.base_url'), '/').'/'.ltrim($action, '/');
        }

        return rtrim(Str::beforeLast($referenceUrl, '/'), '/').'/'.$action;
    }

    /**
     * Build multipart payload for file upload.
     */
    private function buildMultipartPayload(array $fields, string $fileField, UploadedFile $attachment): array
    {
        $multipart = [];

        foreach ($fields as $name => $value) {
            if ($name === $fileField) {
                continue;
            }

            $multipart[] = [
                'name' => $name,
                'contents' => $value,
            ];
        }

        $multipart[] = [
            'name' => $fileField,
            'contents' => fopen($attachment->getRealPath(), 'r'),
            'filename' => $attachment->getClientOriginalName(),
            'headers' => [
                'Content-Type' => $attachment->getMimeType() ?? 'application/octet-stream',
            ],
        ];

        return $multipart;
    }

    /**
     * Send HTTP request with consistent headers.
     */
    private function dispatchRequest(string $method, string $url, array $options, string $referer): ResponseInterface
    {
        $requestOptions = array_merge([
            RequestOptions::COOKIES => $this->cookieJar,
            RequestOptions::HEADERS => [
                'Referer' => $referer,
                'Origin' => config('portal.base_url'),
            ],
        ], $options);

        $delay = config('portal.request_delay', 1000);
        if ($delay > 0) {
            usleep($delay * 1000);
        }

        try {
            $response = $this->client->request($method, $url, $requestOptions);
            $html = (string) $response->getBody();
            $this->validateResponse($html, $url);

            return $response;
        } catch (\Exception $e) {
            $this->logScrapingError('dispatch_request', $e, [
                'url' => $url,
                'method' => $method,
            ]);

            throw $e;
        }
    }

    /**
     * Build result payload with success detection.
     */
    private function buildResultPayload(string $html, string $url, array $context): array
    {
        $crawler = $this->createCrawler($html);
        $alerts = $this->extractAlertMessages($crawler);

        $success = $this->detectSuccessFromHtml($html, $alerts);

        $message = $alerts[0] ?? ($success ? 'Operation completed successfully' : 'Unable to confirm operation result');

        $snippet = Str::limit(strip_tags($html), 500);

        return [
            'success' => $success,
            'message' => $message,
            'data' => array_merge($context, [
                'alerts' => $alerts,
                'html_snippet' => $snippet,
                'endpoint' => $url,
            ]),
        ];
    }

    /**
     * Extract alert messages from response HTML.
     */
    private function extractAlertMessages(Crawler $crawler): array
    {
        $messages = [];

        $crawler->filter('.alert, .alert-success, .alert-error, .alert-warning')->each(function (Crawler $node) use (&$messages): void {
            $messages[] = $this->cleanText($node->text());
        });

        $crawler->filter('.swal2-title, .swal2-html-container')->each(function (Crawler $node) use (&$messages): void {
            $messages[] = $this->cleanText($node->text());
        });

        $crawler->filter('script')->each(function (Crawler $node) use (&$messages): void {
            $text = $node->text();
            if (Str::contains(strtolower($text), ['swal', 'alert', 'berhasil', 'gagal'])) {
                $messages[] = $this->cleanText($text);
            }
        });

        return array_values(array_filter(array_unique($messages)));
    }

    /**
     * Determine success state from HTML and alert messages.
     */
    private function detectSuccessFromHtml(string $html, array $alerts): bool
    {
        $haystack = strtolower($html.' '.implode(' ', $alerts));

        $successIndicators = ['berhasil', 'success', 'tersimpan', 'submitted', 'sukses', 'thank you'];
        $failureIndicators = ['gagal', 'error', 'failed', 'tidak berhasil', 'harus', 'invalid'];

        foreach ($failureIndicators as $indicator) {
            if (Str::contains($haystack, $indicator)) {
                return false;
            }
        }

        foreach ($successIndicators as $indicator) {
            if (Str::contains($haystack, $indicator)) {
                return true;
            }
        }

        // Default fallback when no indicator is found
        return ! empty($alerts);
    }
}
