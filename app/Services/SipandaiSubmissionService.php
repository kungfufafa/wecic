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
            'cmd' => 'Upload',
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

        $result = $this->buildUploadResultPayload((string) $response->getBody(), $actionUrl, [
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
        int $moduleId,
        ?int $meetingNumber = null,
        array $overrides = []
    ): array {
        $this->setSession($sessionToken);

        $targetUrl = $this->buildAttendanceUrl($courseId, $moduleId, $meetingNumber);

        $this->logScraping('Submitting SIPANDAI attendance', [
            'session_token' => $sessionToken,
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'meeting_number' => $meetingNumber,
            'target_url' => $targetUrl,
        ]);

        $pageHtml = $this->get($targetUrl);
        $crawler = $this->createCrawler($pageHtml);
        $payload = $this->buildAttendancePayload(
            $meetingNumber,
            $this->mergeOverrides([], $overrides)
        );

        if ($crawler->filter('form')->count() === 0) {
            $responseUrl = $this->buildAttendanceSubmitUrl($courseId, $moduleId);
            $response = $this->dispatchRequest('POST', $responseUrl, [
                RequestOptions::FORM_PARAMS => $payload,
            ], $targetUrl);

            $result = $this->buildAttendanceResultPayload((string) $response->getBody(), $responseUrl, [
                'course_id' => $courseId,
                'discussion_id' => $moduleId,
                'meeting_number' => $meetingNumber,
            ]);

            $result['data']['redirect_history'] = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);
            $result['data']['sent_fields'] = array_keys($payload);

            return $result;
        }

        $formCrawler = $this->resolveFormForAttendance($crawler);
        $formMeta = $this->extractFormMetadata($formCrawler);
        $payload = $this->buildAttendancePayload(
            $meetingNumber,
            $this->mergeOverrides($formMeta['fields'], $overrides)
        );

        if ($formMeta['enctype'] === 'multipart/form-data' && $formMeta['file_field'] !== null) {
            throw PortalScrapingException::invalidHtmlStructure('attendance form contains file input unexpectedly');
        }

        $actionUrl = $this->resolveActionUrl($formMeta['action'], $targetUrl);

        if (strtoupper($formMeta['method']) === 'GET') {
            $finalUrl = $this->appendQuery($actionUrl, $payload);
            $response = $this->dispatchRequest('GET', $finalUrl, [], $targetUrl);
            $responseUrl = $finalUrl;
        } else {
            $response = $this->dispatchRequest('POST', $actionUrl, [
                RequestOptions::FORM_PARAMS => $payload,
            ], $targetUrl);
            $responseUrl = $actionUrl;
        }

        $result = $this->buildAttendanceResultPayload((string) $response->getBody(), $responseUrl, [
            'course_id' => $courseId,
            'discussion_id' => $moduleId,
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

        return $this->buildDeleteResultPayload((string) $response->getBody(), $targetUrl, [
            'submission_id' => $submissionId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ]);
    }

    /**
     * Upload exam (UAS/UTS) file to SIPANDAI portal via direct POST.
     *
     * @param  string  $sessionToken  Active session token
     * @param  int  $examId  Exam ID from the URL (e.g., id=8833)
     * @param  UploadedFile  $attachment  File to upload (ZIP for praktikum, PDF for teori)
     */
    public function submitExam(
        string $sessionToken,
        int $examId,
        UploadedFile $attachment,
        array $overrides = []
    ): array {
        $this->setSession($sessionToken);

        $formUrl = PortalEndpointRegistry::url('exam_upload_form', ['id' => $examId]);

        $this->logScraping('Uploading SIPANDAI exam (direct POST)', [
            'session_token' => $sessionToken,
            'exam_id' => $examId,
            'file' => $attachment->getClientOriginalName(),
            'form_url' => $formUrl,
        ]);

        $formHtml = $this->get($formUrl);
        $formMeta = $this->extractExamFormMeta($formHtml);

        if (! ($formMeta['has_upload_form'] ?? false) || $formMeta['file_field'] === null) {
            return $this->buildExamUnavailableResultPayload($formHtml, $formUrl, $examId);
        }

        $multipart = [];
        foreach ($formMeta['fields'] as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }

        foreach ($overrides as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => (string) $value];
        }

        $multipart[] = [
            'name' => $formMeta['file_field'] ?? 'file',
            'contents' => fopen($attachment->getRealPath(), 'r'),
            'filename' => $attachment->getClientOriginalName(),
            'headers' => ['Content-Type' => $attachment->getMimeType() ?? 'application/octet-stream'],
        ];

        $multipart[] = ['name' => 'cmd', 'contents' => 'Upload'];

        $actionUrl = $this->resolveExamSubmitUrl((string) $formMeta['action'], $formUrl);

        $this->logScraping('Exam upload form resolved', [
            'action_url' => $actionUrl,
            'file_field' => $formMeta['file_field'],
            'fields' => array_keys($formMeta['fields']),
        ]);

        $response = $this->dispatchRequest('POST', $actionUrl, [
            RequestOptions::MULTIPART => $multipart,
        ], $formUrl);

        $responseHtml = (string) $response->getBody();

        $this->logScraping('Exam upload response received', [
            'response_length' => strlen($responseHtml),
            'has_disimpan' => str_contains(strtolower($responseHtml), 'disimpan'),
            'has_berhasil' => str_contains(strtolower($responseHtml), 'berhasil'),
            'has_gagal' => str_contains(strtolower($responseHtml), 'gagal'),
            'has_error' => str_contains(strtolower($responseHtml), 'error'),
            'url_final' => $actionUrl,
        ]);

        $result = $this->buildUploadResultPayload($responseHtml, $actionUrl, [
            'exam_id' => $examId,
        ]);

        $result['data']['redirect_history'] = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);
        $result['data']['sent_fields'] = array_column($multipart, 'name');
        $result['data']['action_url'] = $actionUrl;

        return $result;
    }

    /**
     * Get detail/upload form metadata for a specific exam.
     */
    public function getExamDetail(string $sessionToken, int $examId): array
    {
        $this->setSession($sessionToken);

        $targetUrl = PortalEndpointRegistry::url('exam_upload_form', ['id' => $examId]);
        $this->logScraping('Fetching SIPANDAI exam detail', [
            'session_token' => $sessionToken,
            'exam_id' => $examId,
            'target_url' => $targetUrl,
        ]);

        $html = $this->get($targetUrl);

        return $this->extractExamDetail($html, $examId, $targetUrl);
    }

    private function extractExamFormMeta(string $html): array
    {
        $crawler = $this->createCrawler($html);
        $forms = $crawler->filter('form');

        if ($forms->count() === 0) {
            return [
                'action' => '',
                'method' => 'post',
                'enctype' => 'application/x-www-form-urlencoded',
                'file_field' => null,
                'fields' => [],
                'has_upload_form' => false,
            ];
        }

        foreach ($forms as $node) {
            $formCrawler = new Crawler($node);

            if ($formCrawler->filter('input[type="file"]')->count() > 0) {
                $formMeta = $this->extractFormMetadata($formCrawler);
                $formMeta['has_upload_form'] = true;

                return $formMeta;
            }
        }

        $formMeta = $this->extractFormMetadata($forms->first());
        $formMeta['has_upload_form'] = false;

        return $formMeta;
    }

    /**
     * Get list of exams for the authenticated student.
     */
    public function getExamList(string $sessionToken): array
    {
        $this->setSession($sessionToken);

        $targetUrl = PortalEndpointRegistry::url('exam_list');
        $this->logScraping('Fetching SIPANDAI exam list', [
            'session_token' => $sessionToken,
        ]);

        $html = $this->get($targetUrl);
        $crawler = $this->createCrawler($html);

        return $this->extractExamList($crawler);
    }

    /**
     * Extract exam list from the exam page.
     */
    private function extractExamList(Crawler $crawler): array
    {
        $exams = [];

        $crawler->filter('table tr')->each(function (Crawler $row) use (&$exams): void {
            $cells = $row->filter('td');
            if ($cells->count() < 4) {
                return;
            }

            $cellTexts = [];
            $cells->each(function (Crawler $cell) use (&$cellTexts): void {
                $cellTexts[] = trim($cell->text());
            });

            $link = $row->filter('a[href*="sendujian"], a[href*="ujian"]');
            if ($link->count() > 0) {
                $href = $link->first()->attr('href') ?? '';
                if (preg_match('/id=(\d+)/', $href, $matches)) {
                    $exams[] = [
                        'id' => (int) $matches[1],
                        'course_code' => $cellTexts[0] ?? '',
                        'course_name' => $cellTexts[1] ?? '',
                        'schedule' => $cellTexts[2] ?? '',
                        'status' => $cellTexts[3] ?? '',
                        'upload_url' => $href,
                    ];
                }
            }
        });

        return $exams;
    }

    private function extractExamDetail(string $html, int $examId, string $targetUrl): array
    {
        $crawler = $this->createCrawler($html);
        $formMeta = $this->extractExamFormMeta($html);
        $alerts = $this->extractAlertMessages($crawler);

        $actionUrl = $this->resolveExamSubmitUrl((string) $formMeta['action'], $targetUrl);

        return [
            'exam_id' => $examId,
            'request_url' => $targetUrl,
            'title' => $this->extractText($crawler, ['title', 'h1', 'h2', '.card-title', '.title']),
            'description' => $this->extractText($crawler, ['.card-body p', 'form p', '.text-muted', '.help-block']),
            'status' => $this->hasUploadedExamInResponse($html) ? 'uploaded' : 'not_uploaded',
            'alerts' => $alerts,
            'requirements' => $this->extractExamRequirementTexts($crawler),
            'related_links' => $this->extractExamRelevantLinks($crawler, $targetUrl),
            'form' => [
                'action_url' => $actionUrl,
                'method' => strtoupper($formMeta['method']),
                'enctype' => $formMeta['enctype'],
                'file_field' => $formMeta['file_field'],
                'available' => $formMeta['has_upload_form'] ?? false,
                'fields' => $formMeta['fields'],
            ],
        ];
    }

    private function resolveExamSubmitUrl(string $action, string $formUrl): string
    {
        $actionUrl = $this->resolveActionUrl($action, $formUrl);

        if ($action === '' || $action === '#' || $this->targetsExamFormPage($actionUrl)) {
            return PortalEndpointRegistry::url('exam_submit');
        }

        return $actionUrl;
    }

    private function targetsExamFormPage(string $actionUrl): bool
    {
        $query = parse_url($actionUrl, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $parameters);

        return ($parameters['act'] ?? null) === 'sendujian';
    }

    private function buildExamUnavailableResultPayload(string $html, string $formUrl, int $examId): array
    {
        $crawler = $this->createCrawler($html);
        $alerts = $this->extractAlertMessages($crawler);
        $alreadyUploaded = $this->hasUploadedExamInResponse($html);

        return [
            'success' => false,
            'message' => $alerts[0] ?? ($alreadyUploaded
                ? 'Exam file already uploaded for this submission.'
                : 'Exam upload form is not available for this submission.'),
            'data' => [
                'status' => $alreadyUploaded ? 'already_uploaded' : 'upload_unavailable',
                'exam_id' => $examId,
                'request_url' => $formUrl,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractExamRequirementTexts(Crawler $crawler): array
    {
        $requirements = [];

        $crawler->filter('p, li, small, .help-block, .text-muted, label')->each(function (Crawler $node) use (&$requirements): void {
            $text = $this->cleanText($node->text());

            if ($text === '') {
                return;
            }

            if (! Str::contains(strtolower($text), ['ujian', 'upload', 'pdf', 'zip', 'rar', 'maks', 'maksimal', 'format'])) {
                return;
            }

            $requirements[] = $text;
        });

        return array_values(array_slice(array_unique($requirements), 0, 10));
    }

    /**
     * @return array<int, array{text: string, url: string}>
     */
    private function extractExamRelevantLinks(Crawler $crawler, string $targetUrl): array
    {
        $links = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $targetUrl): void {
            $href = trim((string) ($node->attr('href') ?? ''));
            $text = $this->cleanText($node->text());
            $haystack = strtolower($href.' '.$text);

            if ($href === '' || ! Str::contains($haystack, ['ujian', 'jawaban', 'download', 'hapus', 'delete', '.pdf', '.zip', '.rar'])) {
                return;
            }

            $links[] = [
                'text' => $text !== '' ? $text : $href,
                'url' => $this->resolveActionUrl($href, $targetUrl),
            ];
        });

        return array_values(array_slice(array_unique($links, SORT_REGULAR), 0, 10));
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
     * Build attendance URL with query parameters (for viewing the page).
     */
    private function buildAttendanceUrl(int $courseId, int $moduleId, ?int $meetingNumber = null): string
    {
        $params = [
            'course_id' => $courseId,
            'id' => $moduleId,
        ];

        if ($meetingNumber !== null) {
            $params['pertemuan'] = $meetingNumber;
        }

        return PortalEndpointRegistry::url('qr_attendance_page', $params);
    }

    private function buildAttendanceSubmitUrl(int $courseId, int $moduleId): string
    {
        return PortalEndpointRegistry::url('attendance_submit', [
            'modulesid' => $moduleId,
            'courseid' => $courseId,
        ]);
    }

    private function resolveFormForAttendance(Crawler $crawler): Crawler
    {
        $forms = $crawler->filter('form');

        foreach ($forms as $node) {
            $formCrawler = new Crawler($node);

            if ($formCrawler->filter('input[name="kode"], input[name="cmd"], button[name="cmd"]')->count() > 0) {
                return $formCrawler;
            }
        }

        return $forms->first();
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
     * Build result payload for UPLOAD operation with proper success detection.
     */
    private function buildUploadResultPayload(string $html, string $url, array $context): array
    {
        $crawler = $this->createCrawler($html);
        $alerts = $this->extractAlertMessages($crawler);
        $status = $this->determineUploadStatus($html, $alerts);

        return [
            'success' => $status['success'],
            'message' => $status['message'],
            'data' => [
                'status' => $status['code'],
                'exam_id' => $context['exam_id'] ?? null,
                'course_id' => $context['course_id'] ?? null,
                'discussion_id' => $context['discussion_id'] ?? null,
                'meeting_number' => $context['meeting_number'] ?? null,
            ],
        ];
    }

    /**
     * Build result payload for ATTENDANCE operation with proper success detection.
     */
    private function buildAttendanceResultPayload(string $html, string $url, array $context): array
    {
        $crawler = $this->createCrawler($html);
        $alerts = $this->extractAlertMessages($crawler);
        $status = $this->determineAttendanceStatus($html, $alerts);

        return [
            'success' => $status['success'],
            'message' => $status['message'],
            'data' => [
                'status' => $status['code'],
                'course_id' => $context['course_id'] ?? null,
                'discussion_id' => $context['discussion_id'] ?? null,
                'meeting_number' => $context['meeting_number'] ?? null,
            ],
        ];
    }

    /**
     * Build result payload for DELETE operation with proper success detection.
     */
    private function buildDeleteResultPayload(string $html, string $url, array $context): array
    {
        $crawler = $this->createCrawler($html);
        $alerts = $this->extractAlertMessages($crawler);
        $status = $this->determineDeleteStatus($html, $alerts, $context['submission_id'] ?? null);

        return [
            'success' => $status['success'],
            'message' => $status['message'],
            'data' => [
                'status' => $status['code'],
                'submission_id' => $context['submission_id'] ?? null,
                'discussion_id' => $context['discussion_id'] ?? null,
                'meeting_number' => $context['meeting_number'] ?? null,
            ],
        ];
    }

    /**
     * Determine UPLOAD status from HTML response.
     *
     * @return array{success: bool, code: string, message: string}
     */
    private function determineUploadStatus(string $html, array $alerts): array
    {
        $haystack = strtolower($html);
        $alertsText = strtolower(implode(' ', $alerts));
        $combinedText = trim($haystack.' '.$alertsText);

        $failureIndicators = [
            'tidak berhasil' => 'Upload was not successful.',
            'gagal' => 'Upload failed.',
            'error' => 'Upload error occurred.',
            'failed' => 'Upload failed.',
            'file tidak valid' => 'Invalid file format.',
            'ukuran file' => 'File size exceeds limit.',
            'format file' => 'Invalid file format.',
            'harus diisi' => 'Required field is missing.',
            'wajib diisi' => 'Required field is missing.',
        ];

        foreach ($failureIndicators as $indicator => $message) {
            if (Str::contains($combinedText, $indicator)) {
                return [
                    'success' => false,
                    'code' => 'upload_failed',
                    'message' => $message,
                ];
            }
        }

        if (Str::contains($combinedText, ['sudah ada', 'sudah diupload', 'already uploaded', 'already exists'])) {
            return [
                'success' => false,
                'code' => 'already_uploaded',
                'message' => 'File already uploaded for this assignment.',
            ];
        }

        if (Str::contains($combinedText, ['deadline', 'batas waktu', 'sudah lewat', 'sudah ditutup'])) {
            return [
                'success' => false,
                'code' => 'deadline_passed',
                'message' => 'Assignment deadline has passed.',
            ];
        }

        $successIndicators = [
            'berhasil diupload' => 'File uploaded successfully.',
            'upload berhasil' => 'File uploaded successfully.',
            'tersimpan' => 'File saved successfully.',
            'sudah disimpan' => 'File saved successfully.',
            'data sudah disimpan' => 'Data saved successfully.',
            'disimpan' => 'File saved successfully.',
            'sukses' => 'Upload successful.',
            'berhasil' => 'Upload successful.',
            'success' => 'Upload successful.',
        ];

        foreach ($successIndicators as $indicator => $message) {
            if (Str::contains($combinedText, $indicator)) {
                return [
                    'success' => true,
                    'code' => 'uploaded',
                    'message' => $message,
                ];
            }
        }

        if ($this->hasUploadedExamInResponse($html)) {
            return [
                'success' => true,
                'code' => 'uploaded',
                'message' => 'Exam file uploaded successfully.',
            ];
        }

        if ($this->hasUploadedFileInResponse($html)) {
            return [
                'success' => true,
                'code' => 'uploaded',
                'message' => 'File uploaded successfully.',
            ];
        }

        return [
            'success' => false,
            'code' => 'unknown',
            'message' => 'Upload status could not be determined. Please verify manually.',
        ];
    }

    /**
     * Determine ATTENDANCE status from HTML response.
     *
     * @return array{success: bool, code: string, message: string}
     */
    private function determineAttendanceStatus(string $html, array $alerts): array
    {
        $haystack = strtolower($html.' '.implode(' ', $alerts));

        // Already attended
        if (Str::contains($haystack, ['sudah melakukan absensi', 'sudah absen', 'already'])) {
            return [
                'success' => false,
                'code' => 'already_marked',
                'message' => 'Attendance already recorded for this meeting.',
            ];
        }

        // Check for successful attendance marking indicators
        if ($this->hasSuccessfulAttendanceIndicator($html)) {
            return [
                'success' => true,
                'code' => 'marked',
                'message' => 'Attendance marked successfully.',
            ];
        }

        // Success with explicit keywords
        if (Str::contains($haystack, ['kehadiran anda telah', 'absensi berhasil', 'tercatat hadir'])) {
            return [
                'success' => true,
                'code' => 'marked',
                'message' => 'Attendance marked successfully.',
            ];
        }

        // Check if this is a QR validation result page with empty result
        if ($this->isEmptyQrValidationResult($html)) {
            return [
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Invalid or expired attendance code.',
            ];
        }

        // Invalid code
        if (Str::contains($haystack, ['kode salah', 'kode tidak valid', 'invalid', 'kode tidak ditemukan'])) {
            return [
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Invalid attendance code.',
            ];
        }

        // Expired / outside time window
        if (Str::contains($haystack, ['expired', 'kadaluarsa', 'waktu habis', 'belum dibuka', 'sudah ditutup'])) {
            return [
                'success' => false,
                'code' => 'expired',
                'message' => 'Attendance window has expired or not yet open.',
            ];
        }

        // If page shows validation result but no success indicator, treat as invalid
        if (Str::contains($haystack, ['hasil validasi'])) {
            return [
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Attendance code validation failed.',
            ];
        }

        // Generic failure - be conservative
        return [
            'success' => false,
            'code' => 'failed',
            'message' => 'Failed to mark attendance. Please verify your code and try again.',
        ];
    }

    /**
     * Determine DELETE status from HTML response.
     *
     * @return array{success: bool, code: string, message: string}
     */
    private function determineDeleteStatus(string $html, array $alerts, int|string|null $submissionId = null): array
    {
        $haystack = strtolower($html.' '.implode(' ', $alerts));

        // Check for explicit failure indicators first
        $failureIndicators = [
            'gagal' => 'Delete failed.',
            'error' => 'Delete error occurred.',
            'failed' => 'Delete failed.',
            'tidak berhasil' => 'Delete was not successful.',
            'tidak ditemukan' => 'Submission not found.',
            'not found' => 'Submission not found.',
            'tidak bisa dihapus' => 'Submission cannot be deleted.',
            'tidak dapat dihapus' => 'Submission cannot be deleted.',
        ];

        foreach ($failureIndicators as $indicator => $message) {
            if (Str::contains($haystack, $indicator)) {
                return [
                    'success' => false,
                    'code' => 'delete_failed',
                    'message' => $message,
                ];
            }
        }

        // Check for permission denied
        if (Str::contains($haystack, ['tidak diizinkan', 'permission', 'akses ditolak', 'unauthorized'])) {
            return [
                'success' => false,
                'code' => 'permission_denied',
                'message' => 'You do not have permission to delete this submission.',
            ];
        }

        // Check for explicit success indicators
        $successIndicators = [
            'berhasil dihapus' => 'Submission deleted successfully.',
            'hapus berhasil' => 'Submission deleted successfully.',
            'terhapus' => 'Submission deleted successfully.',
            'deleted' => 'Submission deleted successfully.',
            'sukses' => 'Delete successful.',
            'berhasil' => 'Delete successful.',
        ];

        foreach ($successIndicators as $indicator => $message) {
            if (Str::contains($haystack, $indicator)) {
                return [
                    'success' => true,
                    'code' => 'deleted',
                    'message' => $message,
                ];
            }
        }

        if ($submissionId !== null && $this->isOnAssignmentListPage($html)) {
            if (! $this->hasSubmissionInResponse($html, (string) $submissionId)) {
                return [
                    'success' => true,
                    'code' => 'deleted',
                    'message' => 'Submission deleted successfully.',
                ];
            }
        }

        // No clear indicator - be conservative
        return [
            'success' => false,
            'code' => 'unknown',
            'message' => 'Delete status could not be determined. Please verify manually.',
        ];
    }

    /**
     * Check if response HTML contains uploaded file indicator.
     */
    private function hasUploadedFileInResponse(string $html): bool
    {
        $haystack = strtolower($html);

        // Look for file listing indicators
        $fileIndicators = [
            'download',
            'hapus',
            'delete',
            'lihat file',
            'file:',
            '.pdf',
            '.doc',
            '.zip',
            'uploaded',
        ];

        $matchCount = 0;
        foreach ($fileIndicators as $indicator) {
            if (Str::contains($haystack, $indicator)) {
                $matchCount++;
            }
        }

        // Need at least 2 indicators to consider it as having uploaded file
        return $matchCount >= 2;
    }

    /**
     * Check if response HTML contains uploaded exam file indicator.
     */
    private function hasUploadedExamInResponse(string $html): bool
    {
        $haystack = strtolower($html);
        if (Str::contains($haystack, ['hapus jawaban', 'open jawaban', 'download jawaban', 'lihat jawaban'])) {
            return true;
        }

        $crawler = $this->createCrawler($html);
        $hasAttachmentLink = false;
        $hasExamActionLink = false;

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$hasAttachmentLink, &$hasExamActionLink): void {
            $href = strtolower(trim((string) ($node->attr('href') ?? '')));
            $text = strtolower($this->cleanText($node->text()));
            $linkContext = trim($href.' '.$text);

            if ($linkContext === '') {
                return;
            }

            if (Str::contains($linkContext, ['open jawaban', 'hapus jawaban', 'download jawaban', 'lihat jawaban'])) {
                $hasExamActionLink = true;
            }

            if (Str::contains($linkContext, ['jawaban', 'hapus', 'delete', 'download', 'open'])) {
                $hasExamActionLink = true;
            }

            if (Str::contains($linkContext, ['.pdf', '.zip', '.rar'])) {
                $hasAttachmentLink = true;
            }
        });

        return $hasAttachmentLink && $hasExamActionLink;
    }

    /**
     * Check if we're on the assignment list page.
     */
    private function isOnAssignmentListPage(string $html): bool
    {
        $haystack = strtolower($html);

        return Str::contains($haystack, ['daftar tugas', 'list tugas', 'assignment list', 'tugas pertemuan']);
    }

    /**
     * Check if submission exists in response.
     */
    private function hasSubmissionInResponse(string $html, ?string $submissionId = null): bool
    {
        $crawler = $this->createCrawler($html);

        if ($submissionId !== null) {
            $hasTargetDeleteLink = $crawler->filter(sprintf('a[href*="id=%s&"]', $submissionId))->count() > 0;

            if ($hasTargetDeleteLink) {
                return true;
            }

            return Str::contains(strtolower($html), [
                'id='.$submissionId,
                'submission-'.$submissionId,
                'data-submission-id="'.$submissionId.'"',
            ]);
        }

        $hasDeleteLink = $crawler->filter('a[href*="hapus"], a[href*="delete"]')->count() > 0;
        $hasFileEntry = $crawler->filter('.file-entry, .submission-item, tr.submission')->count() > 0;

        return $hasDeleteLink || $hasFileEntry;
    }

    /**
     * Check for positive attendance confirmation indicators.
     */
    private function hasSuccessfulAttendanceIndicator(string $html): bool
    {
        $haystack = strtolower($html);

        // Look for explicit success messages only - no assumptions about CSS classes
        $successPatterns = [
            'kehadiran anda berhasil',
            'absensi berhasil',
            'status: hadir',
            'anda telah hadir',
            'tercatat hadir',
            'kehadiran tercatat',
            'absen berhasil disimpan',
            'sukses malakukan absensi',
            'sukses melakukan absensi',
        ];

        foreach ($successPatterns as $pattern) {
            if (Str::contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the HTML shows an empty QR validation result.
     * This indicates the code was submitted but not validated (invalid/expired).
     */
    private function isEmptyQrValidationResult(string $html): bool
    {
        $crawler = $this->createCrawler($html);

        // Check if "Hasil Validasi QR Code" section exists
        $hasValidationSection = Str::contains($html, 'Hasil Validasi QR Code');

        if (! $hasValidationSection) {
            return false;
        }

        // Look for the validation result table - if it's empty, the code is invalid
        // Pattern: <h4 class="title">Hasil Validasi QR Code</h4> followed by empty table
        $validationContent = '';
        $crawler->filter('.card')->each(function (Crawler $card) use (&$validationContent): void {
            $headerText = '';
            $card->filter('.header h4.title, .header .title')->each(function (Crawler $h4) use (&$headerText): void {
                $headerText = $this->cleanText($h4->text());
            });

            if (Str::contains($headerText, 'Hasil Validasi QR Code')) {
                $card->filter('table td')->each(function (Crawler $td) use (&$validationContent): void {
                    $validationContent .= trim($td->text());
                });
            }
        });

        // If validation section exists but content is empty, code is invalid
        return $hasValidationSection && empty(trim($validationContent));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildAttendancePayload(?int $meetingNumber, array $overrides): array
    {
        $payload = array_merge(
            [
                'cmd' => 'Simpan',
            ],
            $meetingNumber !== null ? ['pertemuan' => (string) $meetingNumber] : [],
            $overrides,
        );

        if (! isset($payload['cmd']) || $payload['cmd'] === '') {
            $payload['cmd'] = 'Simpan';
        }

        if ($meetingNumber !== null && (! isset($payload['pertemuan']) || $payload['pertemuan'] === '')) {
            $payload['pertemuan'] = (string) $meetingNumber;
        }

        return $payload;
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
}
