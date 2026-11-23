<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalOperationStage;
use App\Exceptions\PortalException;
use App\Exceptions\PortalScrapingException;
use App\Models\ExamSubmissionRecord;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\DomCrawler\Crawler;

class SipandaiSubmissionService extends PortalScrapingService
{
    private const DEFAULT_ASSIGNMENT_FILE_FIELD = 'data[tugas]';

    private const DEFAULT_EXAM_FILE_FIELD = 'file';

    private const DEFAULT_EXAM_ID_FIELD = 'data[id]';

    private const LEGACY_EXAM_ID_FIELD = 'id';

    /**
     * The write action in progress, for PortalResponseRecorder, with the
     * campus session as it stood when the action began (only taken while
     * recording is on).
     *
     * @var array{action: string, uploaded_file_names: list<string>, session: array<string, mixed>|null}|null
     */
    private ?array $recordedAction = null;

    /**
     * Upload assignment file directly to SIPANDAI portal (`act=sent`).
     *
     * A POST that gets no reply comes back as `unknown` with `transport_error`:
     * the portal may have stored the file before the connection dropped, and the
     * caller checks the assignment page.
     */
    public function submitAssignment(
        string $sessionToken,
        int $courseId,
        int $discussionId,
        int $meetingNumber,
        UploadedFile $attachment,
        array $overrides = [],
        ?callable $onStage = null,
        bool $allowClosed = false
    ): array {
        $this->setSession($sessionToken);
        $this->beginRecordedAction('assignment_upload', [$attachment->getClientOriginalName()]);
        $onStage?->__invoke(PortalOperationStage::FileReceived, 'File tugas diterima server.', 20);

        $targetUrl = $this->buildAssignmentUrl($courseId, $discussionId, $meetingNumber);
        $actionUrl = PortalEndpointRegistry::url('assignment_submit', [
            'courses_id' => $courseId,
            'id' => $discussionId,
            'pertemuan' => $meetingNumber,
        ]);

        $this->logScraping('Uploading SIPANDAI assignment', [
            'session_token' => $sessionToken,
            'course_id' => $courseId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
            'allow_closed' => $allowClosed,
        ]);

        $context = [
            'course_id' => $courseId,
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
        ];
        $extraOverrides = array_diff_key($overrides, array_flip([
            'data[courses_id]',
            'data[id]',
            'data[pertemuan]',
            'data[name]',
            'pertemuan',
            'cmd',
            self::DEFAULT_ASSIGNMENT_FILE_FIELD,
        ]));
        $note = $overrides['data[name]'] ?? $overrides['keterangan'] ?? '';
        $cmd = is_string($overrides['cmd'] ?? null) && trim($overrides['cmd']) !== ''
            ? trim($overrides['cmd'])
            : 'Upload';

        $payload = $this->mergeOverrides([
            'data[courses_id]' => (string) $courseId,
            'data[pertemuan]' => (string) $meetingNumber,
            'data[id]' => (string) $discussionId,
            'data[name]' => is_scalar($note) ? (string) $note : '',
            'cmd' => $cmd,
            'pertemuan' => (string) $meetingNumber,
            ...$extraOverrides,
        ], []);

        $multipart = $this->buildMultipartPayload($payload, self::DEFAULT_ASSIGNMENT_FILE_FIELD, $attachment);

        $onStage?->__invoke(PortalOperationStage::PortalUploading, 'Mengirim file tugas ke portal SIPANDAI.', 55);

        try {
            $response = $this->dispatchRequest(
                'POST',
                $actionUrl,
                [
                    RequestOptions::MULTIPART => $multipart,
                    RequestOptions::TIMEOUT => $this->uploadRequestTimeout(),
                ],
                $targetUrl
            );
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            return [
                'success' => false,
                'message' => 'Portal tidak menjawab saat file dikirim.',
                'data' => ['status' => 'unknown', 'transport_error' => true, ...$context],
            ];
        }

        $result = $this->buildUploadResultPayload((string) $response->getBody(), $actionUrl, $context);

        $onStage?->__invoke(PortalOperationStage::ValidationSucceeded, 'Portal SIPANDAI mengembalikan respons valid.', 80);

        $result['data']['redirect_history'] = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);
        $result['data']['sent_fields'] = array_column($multipart, 'name');
        $result['data']['file_field'] = self::DEFAULT_ASSIGNMENT_FILE_FIELD;

        if (($result['success'] ?? false) === true) {
            $onStage?->__invoke(PortalOperationStage::SubmissionSaved, 'Submission tugas tersimpan.', 100);
        }

        return $result;
    }

    /**
     * Submit attendance confirmation directly to SIPANDAI portal.
     */
    public function submitAttendance(
        string $sessionToken,
        int $courseId,
        int $moduleId,
        ?int $meetingNumber = null,
        array $overrides = []
    ): array {
        $this->setSession($sessionToken);
        $this->beginRecordedAction('attendance_submit');

        $refererUrl = $this->buildAttendanceUrl($courseId, $moduleId, $meetingNumber);
        $qrSubmitUrl = PortalEndpointRegistry::url('attendance_qr_submit', [
            'id' => $moduleId,
            'courses_id' => $courseId,
        ]);

        $this->logScraping('Submitting SIPANDAI attendance', [
            'session_token' => $sessionToken,
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'meeting_number' => $meetingNumber,
            'target_url' => $qrSubmitUrl,
        ]);

        $payload = $this->buildAttendancePayload(
            $meetingNumber,
            $this->mergeOverrides([], $overrides)
        );
        $qrPayload = array_merge($payload, [
            'qrcode' => (string) ($overrides['kode'] ?? $payload['kode'] ?? $overrides['qrcode'] ?? ''),
        ]);

        $response = $this->dispatchRequest('POST', $qrSubmitUrl, [
            RequestOptions::FORM_PARAMS => $qrPayload,
        ], $refererUrl);

        $result = $this->buildAttendanceResultPayload((string) $response->getBody(), $qrSubmitUrl, [
            'course_id' => $courseId,
            'discussion_id' => $moduleId,
            'meeting_number' => $meetingNumber,
        ]);

        if (($result['success'] ?? false) === true) {
            $result['data']['redirect_history'] = $response->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER);
            $result['data']['sent_fields'] = array_keys($qrPayload);

            return $result;
        }

        $responseUrl = $this->buildAttendanceSubmitUrl($courseId, $moduleId);
        $response = $this->dispatchRequest('POST', $responseUrl, [
            RequestOptions::FORM_PARAMS => $payload,
        ], $refererUrl);

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
        $this->beginRecordedAction('assignment_delete');

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
     * Inspect assignment upload page to detect existing uploaded file and delete payload.
     *
     * Only this meeting's submission counts: a `jobdel` link for this
     * discussion and meeting number, or a submitted file (`files/tugasmhs`)
     * that is not another meeting's. Question PDFs, download icons and the
     * student's other uploads say nothing about this assignment.
     *
     * @return array{
     *     status: 'uploaded'|'not_uploaded'|'upload_unavailable'|'unknown',
     *     delete_payload: array{id: string, idx: string, pertemuan: string}|null,
     *     file_info: array{href: string, text: string}|null
     * }
     */
    public function inspectAssignmentSubmissionState(
        string $sessionToken,
        int $courseId,
        int $discussionId,
        int $meetingNumber
    ): array {
        $this->setSession($sessionToken);

        $targetUrl = $this->buildAssignmentUrl($courseId, $discussionId, $meetingNumber);
        $html = $this->get($targetUrl);
        $this->recordActionResponse($html, verification: true);
        $crawler = $this->createCrawler($html);

        $deletePayload = $this->extractAssignmentDeletePayload($crawler, $discussionId, $meetingNumber);
        $fileInfo = $this->extractAssignmentUploadedFileInfo($crawler, $courseId, $meetingNumber);

        if ($deletePayload !== null || $fileInfo !== null) {
            return [
                'status' => 'uploaded',
                'delete_payload' => $deletePayload,
                'file_info' => $fileInfo,
            ];
        }

        $forms = $crawler->filter('form');

        if ($forms->count() > 0) {
            foreach ($forms as $node) {
                if (self::formScope(new Crawler($node))->filter('input[type="file"]')->count() > 0) {
                    return [
                        'status' => 'not_uploaded',
                        'delete_payload' => null,
                        'file_info' => null,
                    ];
                }
            }

            return [
                'status' => 'upload_unavailable',
                'delete_payload' => null,
                'file_info' => null,
            ];
        }

        return [
            'status' => 'unknown',
            'delete_payload' => null,
            'file_info' => null,
        ];
    }

    /**
     * @return array{filename: string, content_type: string, contents: string}
     */
    public function downloadCampusFile(string $sessionToken, string $path, ?string $suggestedName = null): array
    {
        $downloadUrl = PortalUrlGuard::assertCampusFileUrl($path);

        $this->setSession($sessionToken);

        $response = $this->client->request('GET', $downloadUrl, [
            RequestOptions::COOKIES => $this->cookieJar,
            RequestOptions::HEADERS => [
                'Referer' => config('portal.base_url'),
                'Origin' => config('portal.base_url'),
            ],
        ]);

        $contentType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?: 'application/octet-stream');
        $contents = (string) $response->getBody();

        if (str_starts_with(strtolower($contentType), 'text/html')) {
            $this->validateResponse($contents, $downloadUrl, allowShortResponse: true);

            throw PortalScrapingException::invalidResponse($downloadUrl, 'Expected file response');
        }

        return [
            'filename' => $this->resolveDownloadFilename($response, $downloadUrl, $suggestedName),
            'content_type' => $contentType,
            'contents' => $contents,
        ];
    }

    /**
     * A campus file as a stream, for handing large modules and recordings to
     * the browser without holding them in memory or under the short page-read
     * timeout. The transfer gets its own total and idle timeouts; a file whose
     * announced size exceeds the download cap is refused before its body is
     * read. `size` is null when the portal does not announce one.
     *
     * @return array{filename: string, content_type: string, size: int|null, stream: StreamInterface}
     */
    public function streamCampusFile(string $sessionToken, string $path, ?string $suggestedName = null): array
    {
        $downloadUrl = PortalUrlGuard::assertCampusFileUrl($path);

        $this->setSession($sessionToken);

        $response = $this->client->request('GET', $downloadUrl, [
            RequestOptions::COOKIES => $this->cookieJar,
            RequestOptions::HEADERS => [
                'Referer' => config('portal.base_url'),
                'Origin' => config('portal.base_url'),
            ],
            RequestOptions::STREAM => true,
            RequestOptions::TIMEOUT => max(1, (int) config('portal.scraping.download_timeout', 120)),
            RequestOptions::READ_TIMEOUT => max(1, (int) config('portal.scraping.download_read_timeout', 30)),
        ]);

        $body = $response->getBody();
        $contentType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?: 'application/octet-stream');
        $announcedSize = trim($response->getHeaderLine('Content-Length'));
        $size = ctype_digit($announcedSize) ? (int) $announcedSize : null;
        $maxBytes = (int) config('portal.scraping.download_max_bytes', 50 * 1024 * 1024);

        if ($size !== null && $maxBytes > 0 && $size > $maxBytes) {
            $body->close();

            throw PortalScrapingException::invalidResponse($downloadUrl, sprintf('File is %d bytes, above the %d byte download limit', $size, $maxBytes));
        }

        if (str_starts_with(strtolower($contentType), 'text/html')) {
            $head = $body->read(8192);
            $body->close();

            $this->validateResponse($head, $downloadUrl, allowShortResponse: true);

            throw PortalScrapingException::invalidResponse($downloadUrl, 'Expected file response');
        }

        return [
            'filename' => $this->resolveDownloadFilename($response, $downloadUrl, $suggestedName),
            'content_type' => $contentType,
            'size' => $size,
            'stream' => $body,
        ];
    }

    /**
     * Upload exam (UAS/UTS) file directly to SIPANDAI portal (`act=sentujian`).
     *
     * A reply that does not say whether the answer was stored, or a POST that
     * never got a reply, is checked against the sendujian page before it is
     * reported.
     *
     * @param  string  $sessionToken  Active session token
     * @param  int  $examId  Exam ID from the URL (e.g., id=8833)
     * @param  UploadedFile  $attachment  File to upload (ZIP for praktikum, PDF for teori)
     */
    public function submitExam(
        string $sessionToken,
        int $examId,
        UploadedFile $attachment,
        array $overrides = [],
        ?callable $onStage = null
    ): array {
        $this->setSession($sessionToken);
        $this->beginRecordedAction('exam_upload', [$attachment->getClientOriginalName()]);
        $onStage?->__invoke(PortalOperationStage::FileReceived, 'File ujian diterima server.', 20);

        $metadataUrl = PortalEndpointRegistry::url('exam_upload_form', ['id' => $examId]);
        $submitUrl = PortalEndpointRegistry::url('exam_submit');

        $this->logScraping('Uploading SIPANDAI exam', [
            'session_token' => $sessionToken,
            'exam_id' => $examId,
            'file' => $attachment->getClientOriginalName(),
            'metadata_url' => $metadataUrl,
            'submit_url' => $submitUrl,
        ]);

        $payload = $this->mergeOverrides([
            self::DEFAULT_EXAM_ID_FIELD => (string) $examId,
            'data[tipe]' => '',
            'data[kelas]' => '',
            'data[name]' => '',
            'cmd' => 'Upload',
        ], $this->withoutExamProtectedOverrides($overrides));

        $examType = $this->currentExamType(['fields' => $payload]);
        $recordKeysBefore = array_map(
            fn (array $record): string => $this->examSubmissionRecordKey($record),
            $this->examSubmissionsForType($this->storedExamSubmissionRecords($sessionToken, $examId), $examType)
        );
        $multipart = $this->buildMultipartPayload($payload, self::DEFAULT_EXAM_FILE_FIELD, $attachment);

        $this->logScraping('Exam upload endpoints resolved', [
            'metadata_url' => $metadataUrl,
            'submit_url' => $submitUrl,
            'file_field' => self::DEFAULT_EXAM_FILE_FIELD,
            'fields' => array_keys($payload),
        ]);

        $onStage?->__invoke(PortalOperationStage::PortalUploading, 'Mengirim file ujian ke portal SIPANDAI.', 55);

        try {
            $response = $this->dispatchRequest('POST', $submitUrl, [
                RequestOptions::MULTIPART => $multipart,
                RequestOptions::TIMEOUT => $this->uploadRequestTimeout(),
            ], $metadataUrl);
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            $response = null;
        }

        $responseHtml = $response !== null ? (string) $response->getBody() : '';

        $result = $response !== null
            ? $this->buildUploadResultPayload($responseHtml, $submitUrl, ['exam_id' => $examId])
            : [
                'success' => false,
                'message' => 'Portal tidak menjawab saat jawaban dikirim.',
                'data' => ['status' => 'unknown', 'exam_id' => $examId, 'transport_error' => true],
            ];

        $this->logScraping('Exam upload response received', [
            'response_length' => strlen($responseHtml),
            'transport_error' => $response === null,
            'status' => $result['data']['status'] ?? null,
            'url_final' => $submitUrl,
        ]);

        $onStage?->__invoke(PortalOperationStage::ValidationSucceeded, 'Portal SIPANDAI mengembalikan respons.', 80);

        $result['data']['submission_records'] = $this->extractExamSubmissionRecords($responseHtml, $submitUrl, $examId);

        if (($result['data']['status'] ?? null) === 'unknown') {
            $result = $this->verifyExamUpload($examId, $metadataUrl, $examType, $recordKeysBefore, $result);
        }

        $result['data']['redirect_history'] = $response?->getHeader(\GuzzleHttp\RedirectMiddleware::HISTORY_HEADER) ?? [];
        $result['data']['sent_fields'] = array_keys($payload);
        $result['data']['file_field'] = self::DEFAULT_EXAM_FILE_FIELD;
        $result['data']['metadata_url'] = $metadataUrl;
        $result['data']['submit_url'] = $submitUrl;
        $result['data']['action_url'] = $submitUrl;
        $result['data']['direct_submit_fallback'] = false;

        if (($result['success'] ?? false) === true) {
            $result['data']['submission_records'] = $this->rememberUploadedExamSubmissions(
                $sessionToken,
                $examId,
                $metadataUrl,
                $result['data']['submission_records'],
                (bool) ($result['data']['verified'] ?? false)
            );

            $onStage?->__invoke(PortalOperationStage::SubmissionSaved, 'Submission ujian tersimpan.', 100);
        }

        return $result;
    }

    /**
     * The portal's reply did not say whether the answer was stored, or never
     * arrived: the sendujian page tells. A row of the open exam that was not
     * there before the upload confirms it; the upload form shown again
     * without one means the file did not land. Anything else stays unknown.
     *
     * A POST that got no reply at all may still be running on the portal,
     * so a page read right after it proves only a stored answer: without a
     * new row the outcome stays unknown instead of "not uploaded", which
     * would invite a second upload of the same answer.
     *
     * @param  list<string>  $recordKeysBefore
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function verifyExamUpload(int $examId, string $metadataUrl, ?string $examType, array $recordKeysBefore, array $result): array
    {
        $uncertain = [
            'success' => false,
            'message' => 'Status upload ujian belum pasti. Cek halaman ujian sebelum mengunggah ulang.',
            'data' => [...$result['data'], 'status' => 'unknown'],
        ];
        $replyMissing = ($result['data']['transport_error'] ?? false) === true;

        try {
            $html = $this->get($metadataUrl);
            $this->recordActionResponse($html, verification: true);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to verify SIPANDAI exam upload on the sendujian page', [
                'exam_id' => $examId,
                'error' => $throwable->getMessage(),
            ]);

            return $uncertain;
        }

        $formMeta = $this->extractExamFormMeta($html);
        $resolvedExamType = $examType ?? $this->currentExamType($formMeta);
        $records = $this->examSubmissionsForType($this->extractExamSubmissionRecords($html, $metadataUrl, $examId), $resolvedExamType);
        $newRecords = array_filter(
            $records,
            fn (array $record): bool => ! in_array($this->examSubmissionRecordKey($record), $recordKeysBefore, true)
        );

        if ($newRecords !== []) {
            return [
                'success' => true,
                'message' => 'Jawaban ujian tersimpan di portal.',
                'data' => [...$result['data'], 'status' => 'uploaded', 'verified' => true, 'submission_records' => $records],
            ];
        }

        if (! $replyMissing && ($formMeta['has_upload_form'] ?? false) === true) {
            return [
                'success' => false,
                'message' => 'Jawaban ujian belum masuk ke portal.',
                'data' => [...$result['data'], 'status' => 'not_uploaded', 'verified' => true],
            ];
        }

        return $uncertain;
    }

    /**
     * Keeps the answers the portal lists after an accepted upload. This is
     * bookkeeping only: a failure here never turns the portal's confirmation
     * into a failed upload.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function rememberUploadedExamSubmissions(
        string $sessionToken,
        int $examId,
        string $metadataUrl,
        array $records,
        bool $alreadyRefreshed
    ): array {
        try {
            if (! $this->canPersistExamSubmissionRecords()) {
                return $records;
            }

            $this->rememberExamSubmissionRecords($sessionToken, $examId, $records, $alreadyRefreshed ? 'upload_refresh' : 'upload_response');

            if ($alreadyRefreshed) {
                return $records;
            }

            $refreshedHtml = $this->get($metadataUrl);
            $this->recordActionResponse($refreshedHtml, verification: true);
            $refreshedRecords = $this->extractExamSubmissionRecords($refreshedHtml, $metadataUrl, $examId);
            $this->rememberExamSubmissionRecords($sessionToken, $examId, $refreshedRecords, 'upload_refresh');

            return $this->mergeExamSubmissionRecordPayloads($records, $refreshedRecords);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to refresh SIPANDAI exam submission metadata after upload', [
                'exam_id' => $examId,
                'error' => $throwable->getMessage(),
            ]);

            return $records;
        }
    }

    /**
     * Values that identify the exam target or submit action are controlled by
     * the server; client overrides for exam type, class and note are preserved.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function withoutExamProtectedOverrides(array $overrides): array
    {
        $protectedFields = [self::DEFAULT_EXAM_ID_FIELD, self::LEGACY_EXAM_ID_FIELD, 'cmd'];

        return array_diff_key($overrides, array_flip($protectedFields));
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
        $currentSubmissions = $this->extractExamSubmissionRecords($html, $targetUrl, $examId);
        $this->rememberExamSubmissionRecords($sessionToken, $examId, $currentSubmissions, 'detail');

        $detail = $this->extractExamDetail($html, $examId, $targetUrl);
        $storedSubmissions = $this->examSubmissionsForType(
            $this->storedExamSubmissionRecords($sessionToken, $examId),
            $detail['exam_type']
        );

        $detail['stored_submissions'] = $storedSubmissions;
        $detail['latest_submission'] = $this->latestExamSubmissionRecord(
            $detail['submissions'],
            $storedSubmissions
        );

        return $detail;
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
                'field_values' => [],
                'hidden_fields' => [],
                'has_upload_form' => false,
            ];
        }

        $firstFormMeta = null;
        $examFormMeta = null;

        foreach ($forms as $node) {
            $formCrawler = new Crawler($node);
            $formMeta = $this->extractFormMetadata($formCrawler);

            if (self::formScope($formCrawler)->filter('input[type="file"]')->count() > 0) {
                $formMeta['has_upload_form'] = true;

                return $formMeta;
            }

            $firstFormMeta ??= $formMeta;

            if ($examFormMeta === null && $this->looksLikeExamUploadForm($formMeta)) {
                $examFormMeta = $formMeta;
            }
        }

        $formMeta = $examFormMeta ?? $firstFormMeta ?? [
            'action' => '',
            'method' => 'post',
            'enctype' => 'application/x-www-form-urlencoded',
            'file_field' => null,
            'fields' => [],
            'field_values' => [],
            'hidden_fields' => [],
        ];
        $formMeta['has_upload_form'] = false;

        return $formMeta;
    }

    private function prepareExamUploadFormMeta(array $formMeta, int $examId): array
    {
        $fields = $this->prepareExamUploadFields($formMeta['fields'] ?? [], $examId);

        if (($formMeta['file_field'] ?? null) !== null) {
            $formMeta['action'] = PortalEndpointRegistry::url('exam_submit');
            $formMeta['fields'] = $fields;
            $formMeta['direct_submit_fallback'] = false;

            return $formMeta;
        }

        return [
            'action' => PortalEndpointRegistry::url('exam_submit'),
            'method' => 'post',
            'enctype' => 'multipart/form-data',
            'file_field' => self::DEFAULT_EXAM_FILE_FIELD,
            'fields' => $fields,
            'field_values' => $formMeta['field_values'] ?? [],
            'hidden_fields' => $formMeta['hidden_fields'] ?? [],
            'has_upload_form' => false,
            'direct_submit_fallback' => true,
        ];
    }

    private function looksLikeExamUploadForm(array $formMeta): bool
    {
        $action = strtolower((string) ($formMeta['action'] ?? ''));
        $fields = $formMeta['fields'] ?? [];

        return Str::contains($action, ['sendujian', 'sentujian'])
            || array_key_exists(self::DEFAULT_EXAM_ID_FIELD, $fields)
            || (
                array_key_exists('data[tipe]', $fields)
                && array_key_exists('data[kelas]', $fields)
            );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function prepareExamUploadFields(array $fields, int $examId): array
    {
        if (array_key_exists(self::DEFAULT_EXAM_ID_FIELD, $fields)) {
            $fields[self::DEFAULT_EXAM_ID_FIELD] = (string) $examId;

            return $fields;
        }

        if (array_key_exists(self::LEGACY_EXAM_ID_FIELD, $fields)) {
            $fields[self::LEGACY_EXAM_ID_FIELD] = (string) $examId;

            return $fields;
        }

        $fields[self::DEFAULT_EXAM_ID_FIELD] = (string) $examId;

        return $fields;
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
        $seen = [];

        $crawler->filter('table')->each(function (Crawler $table) use (&$exams, &$seen): void {
            $headers = $this->extractExamTableHeaders($table);

            $table->filter('tr')->each(function (Crawler $row) use (&$exams, &$seen, $headers): void {
                $exam = $this->extractExamRow($row, $headers);

                if ($exam === null) {
                    return;
                }

                $key = $exam['id'] !== null
                    ? 'id:'.$exam['id']
                    : implode('|', [
                        $exam['course_code'],
                        $exam['course_name'],
                        $exam['schedule'],
                        $exam['status'],
                    ]);

                if (isset($seen[$key])) {
                    return;
                }

                $seen[$key] = true;
                $exams[] = $exam;
            });
        });

        return $exams;
    }

    /**
     * @return array<int, string>
     */
    private function extractExamTableHeaders(Crawler $table): array
    {
        $headers = [];
        $headerRows = $table->filter('thead tr');

        if ($headerRows->count() === 0) {
            $headerRows = $table->filter('tr')->reduce(function (Crawler $row): bool {
                return $this->looksLikeExamHeaderRow($this->extractCellTexts($row, 'th, td'));
            });
        }

        if ($headerRows->count() === 0) {
            return [];
        }

        $headerRows->first()->filter('th, td')->each(function (Crawler $cell) use (&$headers): void {
            $headers[] = $this->cleanText($cell->text());
        });

        return $headers;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{id: int|null, course_code: string, course_name: string, schedule: string, status: string, upload_url: string|null}|null
     */
    private function extractExamRow(Crawler $row, array $headers): ?array
    {
        $cellTexts = $this->extractCellTexts($row, 'td');

        if (count($cellTexts) < 2 || $this->looksLikeExamHeaderRow($cellTexts)) {
            return null;
        }

        $nonEmptyCells = array_values(array_filter($cellTexts, static fn (string $text): bool => $text !== ''));

        if (count($nonEmptyCells) < 2 || $this->looksLikeStudentInfoRow($nonEmptyCells) || $this->looksLikeEmptyExamRow($nonEmptyCells)) {
            return null;
        }

        $fallbackCells = $this->stripLeadingNumberCell($cellTexts);
        $id = $this->extractExamIdFromRow($row);
        $uploadUrl = $this->extractExamUploadUrlFromRow($row);
        $courseCode = $this->extractExamCellByHeader($cellTexts, $headers, ['kode', 'code', 'kd']);
        $courseName = $this->extractExamCellByHeader($cellTexts, $headers, ['mata kuliah', 'matakuliah', 'matkul', 'course', 'nama']);
        $schedule = $this->extractExamCellByHeader($cellTexts, $headers, ['jadwal', 'tanggal', 'waktu', 'hari', 'jam']);
        $status = $this->extractExamCellByHeader($cellTexts, $headers, ['status', 'keterangan', 'upload', 'aksi']);

        $exam = [
            'id' => $id,
            'course_code' => $courseCode ?? ($fallbackCells[0] ?? ''),
            'course_name' => $courseName ?? ($fallbackCells[1] ?? ''),
            'schedule' => $schedule ?? ($fallbackCells[2] ?? ''),
            'status' => $status ?? ($fallbackCells[3] ?? ''),
            'upload_url' => $uploadUrl,
        ];

        $exam = $this->normalizeExamRow($exam, $fallbackCells);

        if (! $this->hasExamRowSignal($exam, $cellTexts, $headers)) {
            return null;
        }

        return $exam;
    }

    /**
     * @return array<int, string>
     */
    private function extractCellTexts(Crawler $row, string $selector): array
    {
        $cellTexts = [];

        $row->filter($selector)->each(function (Crawler $cell) use (&$cellTexts): void {
            $cellTexts[] = $this->cleanText($cell->text());
        });

        return $cellTexts;
    }

    /**
     * @param  array<int, string>  $cellTexts
     */
    private function looksLikeExamHeaderRow(array $cellTexts): bool
    {
        if ($cellTexts === []) {
            return false;
        }

        $matchedHeaderCells = 0;

        foreach ($cellTexts as $cellText) {
            $normalizedCellText = strtolower($cellText);

            if (preg_match('/^(no|kode|kode mk|kd|mk|mata kuliah|matakuliah|matkul|jadwal|jadwal ujian|tanggal|waktu|hari|jam|status|keterangan|aksi)$/', $normalizedCellText) === 1) {
                $matchedHeaderCells += 1;

                continue;
            }

            if (Str::contains($normalizedCellText, ['kode mk', 'mata kuliah', 'jadwal ujian'])) {
                $matchedHeaderCells += 1;
            }
        }

        return $matchedHeaderCells >= 2;
    }

    /**
     * @param  array<int, string>  $cellTexts
     */
    private function looksLikeEmptyExamRow(array $cellTexts): bool
    {
        $text = strtolower(implode(' ', $cellTexts));

        return Str::contains($text, ['tidak ada data', 'belum ada data', 'data tidak ditemukan', 'no data']);
    }

    /**
     * @param  array<int, string>  $cellTexts
     */
    private function looksLikeStudentInfoRow(array $cellTexts): bool
    {
        $label = strtolower(trim((string) preg_replace('/\s+/', ' ', $cellTexts[0] ?? '')));

        return count($cellTexts) <= 3 && in_array($label, [
            'program studi',
            'th akademik',
            'tahun akademik',
            'semester',
            'n i m',
            'nim',
            'nama',
        ], true);
    }

    /**
     * @param  array<int, string>  $cellTexts
     * @return array<int, string>
     */
    private function stripLeadingNumberCell(array $cellTexts): array
    {
        if (count($cellTexts) > 3 && preg_match('/^\d+\.?$/', $cellTexts[0]) === 1) {
            return array_values(array_slice($cellTexts, 1));
        }

        return $cellTexts;
    }

    private function extractExamIdFromRow(Crawler $row): ?int
    {
        $uploadUrl = $this->extractExamUploadUrlFromRow($row);

        if ($uploadUrl !== null) {
            $id = $this->extractExamIdFromText($uploadUrl);

            if ($id !== null) {
                return $id;
            }
        }

        $idAttributes = [
            $row->attr('data-id'),
            $row->attr('data-exam-id'),
            $row->attr('data-ujian-id'),
        ];

        foreach ($idAttributes as $idAttribute) {
            $id = $this->extractExamIdFromText((string) $idAttribute);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function extractExamUploadUrlFromRow(Crawler $row): ?string
    {
        $hrefs = [];

        $row->filter('a[href]')->each(function (Crawler $link) use (&$hrefs): void {
            $href = trim((string) ($link->attr('href') ?? ''));

            if ($href !== '') {
                $hrefs[] = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
            }
        });

        foreach ($hrefs as $href) {
            if (Str::contains(strtolower($href), ['sendujian', 'ujian'])) {
                return $href;
            }
        }

        foreach ($hrefs as $href) {
            if ($this->extractExamIdFromText($href) !== null) {
                return $href;
            }
        }

        return null;
    }

    private function extractExamIdFromText(string $text): ?int
    {
        $normalizedText = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        if (preg_match('/(?:^|[?&;])(?:id|exam_id|ujian_id)=(\d+)/i', $normalizedText, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\d+$/', trim($normalizedText)) === 1) {
            return (int) $normalizedText;
        }

        return null;
    }

    /**
     * @param  array{id: int|null, course_code: string, course_name: string, schedule: string, status: string, upload_url: string|null}  $exam
     * @param  array<int, string>  $fallbackCells
     * @return array{id: int|null, course_code: string, course_name: string, schedule: string, status: string, upload_url: string|null}
     */
    private function normalizeExamRow(array $exam, array $fallbackCells): array
    {
        $courseInfo = $this->extractExamCourseInfo([
            $exam['course_code'],
            $exam['course_name'],
            $fallbackCells[0] ?? '',
            $fallbackCells[1] ?? '',
        ]);

        if ($courseInfo['code'] !== '') {
            $exam['course_code'] = $courseInfo['code'];
        }

        if ($courseInfo['name'] !== '') {
            $exam['course_name'] = $courseInfo['name'];
        }

        $schedule = $this->extractExamScheduleText([
            $exam['schedule'],
            ...$fallbackCells,
        ]);

        if ($schedule !== '') {
            $exam['schedule'] = $schedule;
        }

        if ($exam['status'] === '' && Str::contains(strtolower($exam['schedule']), 'ujian')) {
            $exam['status'] = 'Terjadwal';
        }

        // The exam list often fills the schedule column with the lecturer's
        // name; a schedule always carries a day or a number.
        $exam['lecturer'] = $exam['lecturer'] ?? null;
        if ($exam['schedule'] !== '' && ! preg_match('/\d|senin|selasa|rabu|kamis|jumat|sabtu|minggu/i', $exam['schedule'])) {
            $exam['lecturer'] = $exam['schedule'];
            $exam['schedule'] = '';
        }

        return $exam;
    }

    /**
     * @param  array<int, string|null>  $candidates
     * @return array{code: string, name: string}
     */
    private function extractExamCourseInfo(array $candidates): array
    {
        $best = [
            'code' => '',
            'name' => '',
        ];

        foreach ($candidates as $candidate) {
            $parsed = $this->parseExamCourseText((string) $candidate);

            if ($parsed['code'] === '') {
                continue;
            }

            if ($this->examCourseInfoScore($parsed) > $this->examCourseInfoScore($best)) {
                $best = $parsed;
            }
        }

        return $best;
    }

    /**
     * Splits "SI043020Machine Learning" into code and name. The digits are
     * matched possessively, so a bare code cell ("SI033017") keeps its last
     * digit instead of giving it up as the course name.
     *
     * @return array{code: string, name: string}
     */
    private function parseExamCourseText(string $text): array
    {
        $normalizedText = $this->cleanText($text);

        if (preg_match('/\b([A-Z]{1,4}\d{4,}+)\s*(.*)$/u', $normalizedText, $matches) !== 1) {
            return [
                'code' => '',
                'name' => '',
            ];
        }

        return [
            'code' => $matches[1],
            'name' => trim($matches[2]),
        ];
    }

    /**
     * @param  array{code: string, name: string}  $courseInfo
     */
    private function examCourseInfoScore(array $courseInfo): int
    {
        return strlen($courseInfo['code']) + strlen($courseInfo['name']) + (preg_match('/^[A-Z]{2,}/', $courseInfo['code']) === 1 ? 20 : 0);
    }

    /**
     * @param  array<int, string|null>  $candidates
     */
    private function extractExamScheduleText(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = $this->cleanText((string) $candidate);

            if ($this->looksLikeExamScheduleText($text)) {
                return $text;
            }
        }

        return '';
    }

    private function looksLikeExamScheduleText(string $text): bool
    {
        $normalizedText = strtolower($text);

        return Str::contains($normalizedText, ['ujian', 'uts', 'uas'])
            || preg_match('/\b(?:senin|selasa|rabu|kamis|jumat|sabtu|minggu)\b.+\d{2}:\d{2}/i', $text) === 1
            || preg_match('/\d{1,2}\s+\w+\s+\d{4}.+\d{2}:\d{2}/i', $text) === 1;
    }

    /**
     * @param  array<int, string>  $cellTexts
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $needles
     */
    private function extractExamCellByHeader(array $cellTexts, array $headers, array $needles): ?string
    {
        foreach ($headers as $index => $header) {
            $normalizedHeader = strtolower($header);

            if (! Str::contains($normalizedHeader, $needles)) {
                continue;
            }

            $value = $cellTexts[$index] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array{id: int|null, course_code: string, course_name: string, schedule: string, status: string, upload_url: string|null}  $exam
     * @param  array<int, string>  $cellTexts
     * @param  array<int, string>  $headers
     */
    private function hasExamRowSignal(array $exam, array $cellTexts, array $headers): bool
    {
        if ($exam['id'] !== null || $exam['upload_url'] !== null) {
            return true;
        }

        if ($exam['course_name'] !== '' && $this->looksLikeExamScheduleText($exam['schedule'])) {
            return true;
        }

        $text = strtolower(implode(' ', array_merge($headers, $cellTexts)));

        return Str::contains($text, ['ujian', 'uts', 'uas'])
            && ($exam['course_code'] !== '' || $exam['course_name'] !== '' || $exam['schedule'] !== '');
    }

    /**
     * The exam counts as submitted only when the page lists an answer row
     * (a `files/tugasmhs` file or a `jobdelujian` link) for the exam that is
     * open now: question files and the earlier UTS answer on a UAS page do not.
     */
    private function extractExamDetail(string $html, int $examId, string $targetUrl): array
    {
        $crawler = $this->createCrawler($html);
        $content = $this->examContentArea($crawler);
        $formMeta = $this->prepareExamUploadFormMeta($this->extractExamFormMeta($html), $examId);
        $alerts = $this->extractAlertMessages($crawler);
        $examType = $this->currentExamType($formMeta);
        $submissions = $this->examSubmissionsForType(
            $this->extractExamSubmissionRecords($html, $targetUrl, $examId),
            $examType
        );

        $submitUrl = PortalEndpointRegistry::url('exam_submit');
        $directSubmitFallback = (bool) ($formMeta['direct_submit_fallback'] ?? false);
        $portalFormOpen = ! $directSubmitFallback && $formMeta['file_field'] !== null;
        $uploadAvailable = $submitUrl !== '';
        $editableFields = array_values(array_diff(
            array_keys($formMeta['fields']),
            $formMeta['hidden_fields'] ?? [],
            [self::DEFAULT_EXAM_ID_FIELD, self::LEGACY_EXAM_ID_FIELD, 'cmd']
        ));

        return [
            'exam_id' => $examId,
            'exam_type' => $examType,
            'request_url' => $targetUrl,
            'metadata_url' => $targetUrl,
            'submit_url' => $submitUrl,
            'title' => $this->extractText($crawler, ['title', 'h1', 'h2', '.card-title', '.title']),
            'description' => $this->extractText($crawler, ['.card-body p', 'form p', '.text-muted', '.help-block']),
            'status' => $submissions !== [] ? 'uploaded' : 'not_uploaded',
            'alerts' => $alerts,
            'requirements' => $this->extractExamRequirementTexts($content),
            'related_links' => $this->extractExamRelevantLinks($content, $targetUrl),
            'submissions' => $submissions,
            'stored_submissions' => [],
            'latest_submission' => $this->latestExamSubmissionRecord($submissions, []),
            'form' => [
                'action_url' => $submitUrl,
                'get_url' => $targetUrl,
                'post_url' => $submitUrl,
                'method' => strtoupper($formMeta['method']),
                'enctype' => $formMeta['enctype'],
                'file_field' => $formMeta['file_field'],
                'available' => $uploadAvailable,
                'portal_form_open' => $portalFormOpen,
                'available_via_direct_submit' => $directSubmitFallback,
                'fields' => $formMeta['fields'],
                'editable_fields' => $editableFields,
            ],
        ];
    }

    /**
     * The exam the sendujian page is open for, from its hidden `data[tipe]`.
     * The real form renders that input twice and may fill only one of them,
     * so the first value that names an exam counts, wherever it stands.
     *
     * @param  array<string, mixed>  $formMeta
     */
    private function currentExamType(array $formMeta): ?string
    {
        $values = $formMeta['field_values']['data[tipe]'] ?? [$formMeta['fields']['data[tipe]'] ?? ''];

        foreach (is_array($values) ? $values : [] as $value) {
            if (preg_match('/\b(UTS|UAS|SP)\b/i', (string) $value, $matches) === 1) {
                return strtoupper($matches[1]);
            }
        }

        return null;
    }

    /**
     * A class's UTS and UAS share one exam id, so its page also lists the
     * answer sent for the other exam. Rows whose type cannot be read are kept.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function examSubmissionsForType(array $records, ?string $examType): array
    {
        if ($examType === null) {
            return array_values($records);
        }

        return array_values(array_filter($records, function (array $record) use ($examType): bool {
            $recordType = $this->examSubmissionRecordType($record);

            return $recordType === null || $recordType === $examType;
        }));
    }

    /**
     * Read from the "Ujian" column ("UTS / Reguler") or the answer's file
     * name ("9195-UTS-<nim>-785.pdf").
     *
     * @param  array<string, mixed>  $record
     */
    private function examSubmissionRecordType(array $record): ?string
    {
        if (preg_match('/\b(UTS|UAS|SP)\b/i', (string) ($record['exam_type'] ?? ''), $matches) === 1) {
            return strtoupper($matches[1]);
        }

        $fileName = (string) ($record['file_name'] ?? ($record['file_url'] ?? ''));

        if (preg_match('/-(UTS|UAS|SP)-/i', $fileName, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractExamSubmissionRecords(string $html, string $targetUrl, int $examId): array
    {
        $crawler = $this->createCrawler($html);
        $records = [];

        $crawler->filter('tr')->each(function (Crawler $row) use (&$records, $targetUrl, $examId): void {
            $record = $this->extractExamSubmissionRecordFromRow($row, $targetUrl, $examId);

            if ($record !== null) {
                $records[] = $record;
            }
        });

        if ($records === []) {
            $record = $this->extractExamSubmissionRecordFromLinks($crawler, $targetUrl, $examId);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $this->uniqueExamSubmissionRecords($records);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractExamSubmissionRecordFromRow(Crawler $row, string $targetUrl, int $examId): ?array
    {
        $fileLink = null;
        $deleteLink = null;

        $row->filter('a[href]')->each(function (Crawler $link) use (&$fileLink, &$deleteLink): void {
            $href = trim((string) ($link->attr('href') ?? ''));
            $text = $this->cleanText($link->text());

            if ($fileLink === null && $this->isExamSubmissionFileLink($href, $text)) {
                $fileLink = [
                    'href' => $href,
                    'text' => $text,
                ];
            }

            if ($deleteLink === null && $this->isExamSubmissionDeleteLink($href)) {
                $deleteLink = [
                    'href' => $href,
                    'text' => $text,
                ];
            }
        });

        if ($fileLink === null && $deleteLink === null) {
            return null;
        }

        $cellTexts = $this->extractCellTexts($row, 'td, th');

        return $this->buildExamSubmissionRecord(
            $examId,
            $targetUrl,
            $fileLink,
            $deleteLink,
            $cellTexts
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractExamSubmissionRecordFromLinks(Crawler $crawler, string $targetUrl, int $examId): ?array
    {
        $fileLink = null;
        $deleteLink = null;

        $crawler->filter('a[href]')->each(function (Crawler $link) use (&$fileLink, &$deleteLink): void {
            $href = trim((string) ($link->attr('href') ?? ''));
            $text = $this->cleanText($link->text());

            if ($fileLink === null && $this->isExamSubmissionFileLink($href, $text)) {
                $fileLink = [
                    'href' => $href,
                    'text' => $text,
                ];
            }

            if ($deleteLink === null && $this->isExamSubmissionDeleteLink($href)) {
                $deleteLink = [
                    'href' => $href,
                    'text' => $text,
                ];
            }
        });

        if ($fileLink === null && $deleteLink === null) {
            return null;
        }

        return $this->buildExamSubmissionRecord($examId, $targetUrl, $fileLink, $deleteLink, []);
    }

    /**
     * @param  array{href: string, text: string}|null  $fileLink
     * @param  array{href: string, text: string}|null  $deleteLink
     * @param  array<int, string>  $cellTexts
     * @return array<string, mixed>
     */
    private function buildExamSubmissionRecord(
        int $examId,
        string $targetUrl,
        ?array $fileLink,
        ?array $deleteLink,
        array $cellTexts
    ): array {
        $fileUrl = $fileLink !== null
            ? $this->resolveExamRelevantLinkUrl($fileLink['href'], $targetUrl)
            : null;
        $deleteUrl = $deleteLink !== null
            ? $this->resolveExamRelevantLinkUrl($deleteLink['href'], $targetUrl)
            : null;

        return [
            'exam_id' => $examId,
            'submission_id' => $deleteLink !== null ? $this->extractExamDeleteSubmissionId($deleteLink['href']) : null,
            'file_url' => $fileUrl,
            'file_name' => $fileUrl !== null ? $this->filenameFromUrl($fileUrl) : null,
            'delete_url' => $deleteUrl,
            'uploaded_at_label' => $cellTexts[1] ?? null,
            'exam_type' => $cellTexts[2] ?? null,
            'description' => $cellTexts[3] ?? null,
            'is_stored' => false,
        ];
    }

    /**
     * An answer the student sent: a file under `files/tugasmhs/`, or a link
     * labelled "Open/Lihat Jawaban" that points somewhere. Headings, tabs and
     * buttons that only mention the answer ("Upload Jawaban Ujian",
     * `#form-upload`) and question files are not answers.
     */
    private function isExamSubmissionFileLink(string $href, string $text): bool
    {
        $target = strtolower(trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8')));
        $label = strtolower($text);

        if (Str::contains($target.' '.$label, ['soal', 'jobdelujian'])) {
            return false;
        }

        if (Str::contains($target, 'files/tugasmhs')) {
            return true;
        }

        if ($target === '' || str_starts_with($target, '#') || str_starts_with($target, 'javascript:') || Str::contains($target, 'act=sendujian')) {
            return false;
        }

        return Str::contains($label, ['open jawaban', 'lihat jawaban'])
            && ! Str::contains($label, ['upload', 'unggah', 'kirim']);
    }

    private function isExamSubmissionDeleteLink(string $href): bool
    {
        return Str::contains(strtolower(html_entity_decode($href, ENT_QUOTES, 'UTF-8')), 'jobdelujian');
    }

    private function extractExamDeleteSubmissionId(string $href): ?int
    {
        parse_str((string) parse_url(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);

        $submissionId = $query['id'] ?? null;

        return is_numeric($submissionId) ? (int) $submissionId : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function uniqueExamSubmissionRecords(array $records): array
    {
        $uniqueRecords = [];
        $seen = [];

        foreach ($records as $record) {
            $key = $this->examSubmissionRecordKey($record);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $uniqueRecords[] = $record;
        }

        return $uniqueRecords;
    }

    /**
     * @param  array<int, array<string, mixed>>  $primaryRecords
     * @param  array<int, array<string, mixed>>  $secondaryRecords
     * @return array<int, array<string, mixed>>
     */
    private function mergeExamSubmissionRecordPayloads(array $primaryRecords, array $secondaryRecords): array
    {
        return $this->uniqueExamSubmissionRecords(array_merge($primaryRecords, $secondaryRecords));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function examSubmissionRecordKey(array $record): string
    {
        if (($record['submission_id'] ?? null) !== null) {
            return 'submission:'.$record['submission_id'];
        }

        if (($record['delete_url'] ?? null) !== null) {
            return 'delete:'.strtolower((string) $record['delete_url']);
        }

        return 'file:'.strtolower((string) ($record['file_url'] ?? spl_object_id((object) $record)));
    }

    private function persistedExamSubmissionRecordKey(?string $username, int $examId, array $record): string
    {
        $identity = $this->examSubmissionRecordKey($record);

        return hash('sha256', implode('|', [
            $username ?? 'anonymous',
            (string) $examId,
            $identity,
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentRecords
     * @param  array<int, array<string, mixed>>  $storedRecords
     * @return array<string, mixed>|null
     */
    private function latestExamSubmissionRecord(array $currentRecords, array $storedRecords): ?array
    {
        foreach ([$currentRecords, $storedRecords] as $records) {
            foreach ($records as $record) {
                if (($record['submission_id'] ?? null) !== null) {
                    return $record;
                }
            }
        }

        return $currentRecords[0] ?? $storedRecords[0] ?? null;
    }

    /**
     * Exam ids are shared by a whole class: rows saved without an owner would
     * be read back for every student whose session lookup fails, so nothing
     * is kept while the student is unknown.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function rememberExamSubmissionRecords(string $sessionToken, int $examId, array $records, string $source): void
    {
        if ($records === [] || ! $this->canPersistExamSubmissionRecords()) {
            return;
        }

        $username = $this->sessionUsernameForSubmissionRecords($sessionToken);

        if ($username === null) {
            return;
        }

        foreach ($records as $record) {
            $recordKey = $this->persistedExamSubmissionRecordKey($username, $examId, $record);

            ExamSubmissionRecord::query()->updateOrCreate([
                'record_key' => $recordKey,
            ], [
                'username' => $username,
                'exam_id' => $examId,
                'record_key' => $recordKey,
                'submission_id' => $record['submission_id'] ?? null,
                'file_url' => $record['file_url'] ?? null,
                'file_name' => $record['file_name'] ?? null,
                'delete_url' => $record['delete_url'] ?? null,
                'uploaded_at_label' => $record['uploaded_at_label'] ?? null,
                'exam_type' => $record['exam_type'] ?? null,
                'description' => $record['description'] ?? null,
                'source' => $source,
                'metadata' => [],
                'last_seen_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedExamSubmissionRecords(string $sessionToken, int $examId): array
    {
        if (! $this->canPersistExamSubmissionRecords()) {
            return [];
        }

        $username = $this->sessionUsernameForSubmissionRecords($sessionToken);

        if ($username === null) {
            return [];
        }

        return ExamSubmissionRecord::query()
            ->where('exam_id', $examId)
            ->where('username', $username)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ExamSubmissionRecord $record): array => $this->examSubmissionRecordPayload($record))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function examSubmissionRecordPayload(ExamSubmissionRecord $record): array
    {
        return [
            'exam_id' => $record->exam_id,
            'submission_id' => $record->submission_id,
            'file_url' => $record->file_url,
            'file_name' => $record->file_name,
            'delete_url' => $record->delete_url,
            'uploaded_at_label' => $record->uploaded_at_label,
            'exam_type' => $record->exam_type,
            'description' => $record->description,
            'source' => $record->source,
            'last_seen_at' => $record->last_seen_at?->toISOString(),
            'is_stored' => true,
        ];
    }

    private function canPersistExamSubmissionRecords(): bool
    {
        try {
            return Schema::hasTable('exam_submission_records');
        } catch (\Throwable) {
            return false;
        }
    }

    private function sessionUsernameForSubmissionRecords(string $sessionToken): ?string
    {
        try {
            $session = $this->sessionManager->getSession($sessionToken);
        } catch (\Throwable) {
            return null;
        }

        $username = $session['username'] ?? null;

        return is_scalar($username) && $username !== '' ? (string) $username : null;
    }

    /**
     * The exam's own part of a SIPANDAI page, `.app-content`: the header and
     * sidebar before it hold the menu ("Ujian (UTS/UAS/SP)"), which is no
     * note about the exam. A page without that layout is read whole.
     */
    private function examContentArea(Crawler $crawler): Crawler
    {
        $content = $crawler->filter('.app-content');

        return $content->count() > 0 ? $content->first() : $crawler;
    }

    /**
     * The exam's notes and upload rules, the numbered "PERHATIAN" lines
     * (`h6`) included; the portal's welcome title is not one of them.
     *
     * @return array<int, string>
     */
    private function extractExamRequirementTexts(Crawler $crawler): array
    {
        $requirements = [];

        $crawler->filter('p, li, small, .help-block, .text-muted, label, h6:not(.app-page-title)')->each(function (Crawler $node) use (&$requirements): void {
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
     * @return array<int, array{text: string, url: string, type: string, downloadable: bool}>
     */
    private function extractExamRelevantLinks(Crawler $crawler, string $targetUrl): array
    {
        $links = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $targetUrl): void {
            $href = trim((string) ($node->attr('href') ?? ''));
            $text = $this->cleanText($node->text());

            $this->appendExamRelevantLink($links, $href, $text, $targetUrl);
        });

        $crawler->filter('iframe[src], embed[src]')->each(function (Crawler $node) use (&$links, $targetUrl): void {
            $src = trim((string) ($node->attr('src') ?? ''));
            $title = $this->cleanText((string) ($node->attr('title') ?? ''));

            $this->appendExamRelevantLink($links, $src, $title, $targetUrl);
        });

        $html = $crawler->html();
        if (is_string($html) && preg_match_all('#(?:https?://[^\s"\'<>]+|(?:\.\./|\.\/|/)?(?:portal/)?files/[^\s"\'<>]+)#i', $html, $matches) === 1) {
            foreach ($matches[0] as $url) {
                $this->appendExamRelevantLink($links, html_entity_decode($url, ENT_QUOTES, 'UTF-8'), '', $targetUrl);
            }
        }

        return array_values(array_slice($this->uniqueExamRelevantLinks($links), 0, 10));
    }

    /**
     * @param  array<int, array{text: string, url: string, type: string, downloadable: bool}>  $links
     * @return array<int, array{text: string, url: string, type: string, downloadable: bool}>
     */
    private function uniqueExamRelevantLinks(array $links): array
    {
        $uniqueLinks = [];
        $seen = [];

        foreach ($links as $link) {
            $key = strtolower($link['url']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $uniqueLinks[] = $link;
        }

        return $uniqueLinks;
    }

    /**
     * @param  array<int, array{text: string, url: string, type: string, downloadable: bool}>  $links
     */
    private function appendExamRelevantLink(array &$links, string $href, string $text, string $targetUrl): void
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
        $text = $this->cleanText($text);
        $haystack = strtolower($href.' '.$text);

        if ($href === '' || ! Str::contains($haystack, ['ujian', 'soal', 'jawaban', 'lampiran', 'download', 'hapus', 'delete', '.pdf', '.zip', '.rar', '.doc', '.docx'])) {
            return;
        }

        $url = $this->resolveExamRelevantLinkUrl($href, $targetUrl);
        $downloadable = $this->isDownloadableExamLink($url);

        $links[] = [
            'text' => $text !== '' ? $text : $this->filenameFromUrl($url),
            'url' => $url,
            'type' => $downloadable ? 'file' : (Str::contains($haystack, ['hapus', 'delete']) ? 'action' : 'link'),
            'downloadable' => $downloadable,
        ];
    }

    private function resolveExamRelevantLinkUrl(string $href, string $targetUrl): string
    {
        $normalizedPath = preg_replace('#^(?:\.\./|\.\/)+#', '', str_replace('\\', '/', trim($href)));
        $normalizedPath = ltrim((string) $normalizedPath, '/');

        if (Str::startsWith($normalizedPath, 'portal/files/')) {
            return rtrim(config('portal.base_url'), '/').'/'.$normalizedPath;
        }

        if (Str::startsWith($normalizedPath, 'files/')) {
            return rtrim(config('portal.base_url'), '/').'/portal/'.$normalizedPath;
        }

        return $this->resolveActionUrl($href, $targetUrl);
    }

    private function isDownloadableExamLink(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return Str::contains($path, '/portal/files/')
            || preg_match('/\.(pdf|zip|rar|doc|docx|xls|xlsx|ppt|pptx)$/i', $path) === 1;
    }

    private function filenameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path);

        return $filename !== '' ? rawurldecode($filename) : $url;
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

    private function buildAssignmentDeleteUrl(int $submissionId, int $discussionId, int $meetingNumber): string
    {
        return PortalEndpointRegistry::url('assignment_delete', [
            'id' => $submissionId,
            'idx' => $discussionId,
            'pertemuan' => $meetingNumber,
        ]);
    }

    /**
     * Extract form metadata for submission. `hidden_fields` names the inputs
     * the portal fills in itself (exam type, class), which a student must not
     * be offered to edit.
     *
     * `fields` holds one value per name, the last one, as the portal's PHP
     * reads a repeated field; `field_values` keeps every input's value in
     * document order (the sendujian form repeats `data[tipe]`).
     */
    private function extractFormMetadata(Crawler $form): array
    {
        $fields = [];
        $fieldValues = [];
        $hiddenFields = [];
        $fileField = null;
        $controls = self::formScope($form);

        $controls->filter('input')->each(function (Crawler $input) use (&$fields, &$fieldValues, &$hiddenFields, &$fileField): void {
            $name = $input->attr('name');
            if (! $name) {
                return;
            }

            $type = strtolower($input->attr('type') ?? 'text');

            if ($type === 'file') {
                $fileField = $name;

                return;
            }

            if ($type === 'hidden') {
                $hiddenFields[] = $name;
            }

            if ($type === 'checkbox' || $type === 'radio') {
                if ($input->attr('checked') === null) {
                    return;
                }
            }

            $fields[$name] = $input->attr('value') ?? '';
            $fieldValues[$name][] = $fields[$name];
        });

        $controls->filter('textarea')->each(function (Crawler $textarea) use (&$fields): void {
            $name = $textarea->attr('name');
            if (! $name) {
                return;
            }

            $fields[$name] = trim($textarea->text());
        });

        $controls->filter('select')->each(function (Crawler $select) use (&$fields): void {
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

        $controls->filter('button[name]')->each(function (Crawler $button) use (&$fields): void {
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
            'field_values' => $fieldValues,
            'hidden_fields' => array_values(array_unique($hiddenFields)),
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
     * Resolve form action URL against reference URL.
     */
    private function resolveActionUrl(string $action, string $referenceUrl): string
    {
        return PortalUrlGuard::resolve($action, $referenceUrl);
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
    /**
     * Sending a file to the portal can outlast the short timeout used for
     * page reads in web requests.
     */
    private function uploadRequestTimeout(): int
    {
        return max(1, (int) config('portal.actions.upload_request_timeout', 120));
    }

    private function dispatchRequest(string $method, string $url, array $options, string $referer): ResponseInterface
    {
        $requestOptions = array_merge([
            RequestOptions::COOKIES => $this->cookieJar,
            RequestOptions::HEADERS => [
                'Referer' => $referer,
                'Origin' => config('portal.base_url'),
            ],
        ], $options);

        $this->pauseBetweenRequests();
        $this->forgetMemoizedPages();

        try {
            $response = $this->client->request($method, $url, $requestOptions);
            $html = (string) $response->getBody();
            $this->recordActionResponse($html);
            $this->validateResponse($html, $url, allowShortResponse: true);

            return $response;
        } catch (\Exception $e) {
            if ($e instanceof RequestException && $e->getResponse() !== null) {
                $this->recordActionResponse((string) $e->getResponse()->getBody());
            }

            $this->logScrapingError('dispatch_request', $e, [
                'url' => $url,
                'method' => $method,
            ]);

            throw $e;
        }
    }

    /**
     * Name the write action this service performs next, so the portal's
     * answers to it (and the pages read to verify it) are recorded under
     * that name, with the uploaded file names sanitized away. While
     * recording is on, the campus session is kept as it stands now, so a
     * session that ends during a long upload still sanitizes the answer.
     *
     * @param  list<string>  $uploadedFileNames
     */
    private function beginRecordedAction(string $action, array $uploadedFileNames = []): void
    {
        try {
            $session = app(PortalResponseRecorder::class)->isEnabled() ? $this->sessionForRecording() : null;
        } catch (\Throwable) {
            $session = null;
        }

        $this->recordedAction = ['action' => $action, 'uploaded_file_names' => $uploadedFileNames, 'session' => $session];
    }

    /**
     * Hand the portal's answer to the current write action, or with
     * `$verification` a page read to verify it, to PortalResponseRecorder,
     * which keeps a sanitized copy while portal.debug.record_action_responses
     * is on. The answer is sanitized with the current campus session, or the
     * one the action began with once that is gone; with neither, nothing is
     * kept. Reads outside a write action are not recorded, and recording
     * never changes the action's outcome.
     */
    private function recordActionResponse(string $html, bool $verification = false): void
    {
        if ($this->recordedAction === null) {
            return;
        }

        try {
            $recorder = app(PortalResponseRecorder::class);

            if (! $recorder->isEnabled()) {
                return;
            }

            $recorder->record(
                $this->recordedAction['action'].($verification ? '_verify' : ''),
                $html,
                $this->sessionForRecording() ?? $this->recordedAction['session'],
                $this->recordedAction['uploaded_file_names']
            );
        } catch (\Throwable $throwable) {
            Log::warning('Failed to record a portal action response', [
                'action' => $this->recordedAction['action'],
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * The stored campus session of this service's session token, for
     * PortalResponseRecorder to sanitize with.
     *
     * @return array<string, mixed>|null
     */
    private function sessionForRecording(): ?array
    {
        return $this->sessionToken !== null ? $this->sessionManager->getSession($this->sessionToken) : null;
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
     * Alerts carry the portal's verdict, so they are read first. The rest of
     * the page is matched on its visible text only, and only on phrases that
     * describe a result: labels such as "Batas Waktu" or "Ukuran file" and
     * words in scripts or styles appear on every normal page.
     *
     * Links and file names prove nothing: every portal page carries download
     * icons and question PDFs. Without a verdict the result is `unknown`, and
     * the caller re-reads the upload page to learn whether the file landed.
     *
     * @return array{success: bool, code: string, message: string}
     */
    private function determineUploadStatus(string $html, array $alerts): array
    {
        $alertsText = strtolower(implode(' ', $alerts));

        $alertStatus = $alertsText !== '' ? $this->matchUploadStatus($alertsText, [
            'tidak berhasil' => 'Upload tidak berhasil.',
            'gagal' => 'Upload gagal.',
            'error' => 'Terjadi kesalahan saat upload.',
            'failed' => 'Upload gagal.',
            'file tidak valid' => 'Format file tidak diterima portal.',
            'ukuran file' => 'Ukuran file melebihi batas portal.',
            'format file' => 'Format file tidak diterima portal.',
            'harus diisi' => 'Ada isian wajib yang kosong di portal.',
            'wajib diisi' => 'Ada isian wajib yang kosong di portal.',
        ], ['deadline', 'batas waktu', 'sudah lewat', 'sudah ditutup']) : null;

        if ($alertStatus !== null) {
            return $alertStatus;
        }

        $pageStatus = $this->matchUploadStatus($this->visibleText($html), [
            'tidak berhasil' => 'Upload tidak berhasil.',
            'gagal' => 'Upload gagal.',
            'file tidak valid' => 'Format file tidak diterima portal.',
            'harus diisi' => 'Ada isian wajib yang kosong di portal.',
            'wajib diisi' => 'Ada isian wajib yang kosong di portal.',
        ], ['sudah lewat', 'sudah ditutup']);

        if ($pageStatus !== null) {
            return $pageStatus;
        }

        return [
            'success' => false,
            'code' => 'unknown',
            'message' => 'Status upload belum bisa dipastikan dari jawaban portal.',
        ];
    }

    /**
     * @param  array<string, string>  $failureIndicators
     * @param  list<string>  $deadlineIndicators
     * @return array{success: bool, code: string, message: string}|null
     */
    private function matchUploadStatus(string $text, array $failureIndicators, array $deadlineIndicators): ?array
    {
        foreach ($failureIndicators as $indicator => $message) {
            if (Str::contains($text, $indicator)) {
                return [
                    'success' => false,
                    'code' => 'upload_failed',
                    'message' => $message,
                ];
            }
        }

        if (Str::contains($text, ['sudah ada', 'sudah diupload', 'already uploaded', 'already exists'])) {
            return [
                'success' => false,
                'code' => 'already_uploaded',
                'message' => 'File untuk tugas ini sudah pernah diupload.',
            ];
        }

        if (Str::contains($text, $deadlineIndicators)) {
            return [
                'success' => false,
                'code' => 'deadline_passed',
                'message' => 'Batas waktu pengumpulan sudah lewat.',
            ];
        }

        $successIndicators = [
            'berhasil diupload' => 'File berhasil diupload.',
            'upload berhasil' => 'File berhasil diupload.',
            'tersimpan' => 'File tersimpan di portal.',
            'sudah disimpan' => 'File tersimpan di portal.',
            'data sudah disimpan' => 'Data tersimpan di portal.',
            'disimpan' => 'File tersimpan di portal.',
            'sukses' => 'Upload berhasil.',
            'berhasil' => 'Upload berhasil.',
            'success' => 'Upload berhasil.',
        ];

        foreach ($successIndicators as $indicator => $message) {
            if (Str::contains($text, $indicator)) {
                return [
                    'success' => true,
                    'code' => 'uploaded',
                    'message' => $message,
                ];
            }
        }

        return null;
    }

    /**
     * Page text as the student sees it, without scripts, styles and markup.
     *
     * Read from the parsed page: strip_tags() stops at the first malformed
     * tag, and the sidebar of every SIPANDAI page has one (`</span` without
     * its `>`), so everything below it would be lost.
     */
    private function visibleText(string $html): string
    {
        $crawler = $this->createCrawler($html);

        foreach ($crawler->filter('script, style, noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }

        return strtolower($this->cleanText($crawler->text('')));
    }

    /**
     * Determine ATTENDANCE status from HTML response.
     *
     * @return array{success: bool, code: string, message: string}
     */
    private function determineAttendanceStatus(string $html, array $alerts): array
    {
        $haystack = strtolower($html.' '.implode(' ', $alerts));
        $alertsText = strtolower(implode(' ', $alerts));

        // Already attended: the goal of the action is met, so it counts as a
        // success. Short or generic phrases are only trusted inside alerts.
        if (Str::contains($haystack, 'sudah melakukan absensi') || Str::contains($alertsText, ['sudah absen', 'already'])) {
            return [
                'success' => true,
                'code' => 'already_marked',
                'message' => 'Kehadiran sudah tercatat untuk pertemuan ini.',
            ];
        }

        // Check for successful attendance marking indicators
        if ($this->hasSuccessfulAttendanceIndicator($html)) {
            return [
                'success' => true,
                'code' => 'marked',
                'message' => 'Absensi berhasil dicatat.',
            ];
        }

        // Success with explicit keywords
        if (Str::contains($haystack, ['kehadiran anda telah', 'absensi berhasil', 'tercatat hadir'])) {
            return [
                'success' => true,
                'code' => 'marked',
                'message' => 'Absensi berhasil dicatat.',
            ];
        }

        // Check if this is a QR validation result page with empty result
        if ($this->isEmptyQrValidationResult($html)) {
            return [
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Kode absen salah atau sudah kedaluwarsa.',
            ];
        }

        // Invalid code
        if (Str::contains($haystack, ['kode salah', 'kode tidak valid', 'invalid', 'kode tidak ditemukan'])) {
            return [
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Kode absen salah.',
            ];
        }

        // Expired / outside time window
        if (Str::contains($haystack, ['expired', 'kadaluarsa', 'waktu habis', 'belum dibuka', 'sudah ditutup', 'berakhir pada jam'])) {
            return [
                'success' => false,
                'code' => 'expired',
                'message' => 'Sesi absensi sudah ditutup atau belum dibuka.',
            ];
        }

        // Data kelas tidak ditemukan (portal memunculkan ini saat kode salah atau sesi absensi sudah ditutup)
        if (Str::contains($haystack, ['data kelas tidak ditemukan'])) {
            return [
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Data kelas tidak ditemukan di portal (kemungkinan batas waktu absensi sudah berakhir atau kode presensi tidak sesuai).',
            ];
        }

        // If page shows validation result but no success indicator, treat as invalid
        if (Str::contains($haystack, ['hasil validasi'])) {
            return [
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Kode absen tidak lolos validasi portal.',
            ];
        }

        // Generic failure - be conservative
        return [
            'success' => false,
            'code' => 'failed',
            'message' => 'Absensi gagal dicatat. Periksa kode lalu coba lagi.',
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
            'gagal' => 'Hapus gagal.',
            'error' => 'Terjadi kesalahan saat menghapus.',
            'failed' => 'Hapus gagal.',
            'tidak berhasil' => 'Hapus tidak berhasil.',
            'tidak ditemukan' => 'Tugas tidak ditemukan di portal.',
            'not found' => 'Tugas tidak ditemukan di portal.',
            'tidak bisa dihapus' => 'Tugas tidak bisa dihapus.',
            'tidak dapat dihapus' => 'Tugas tidak bisa dihapus.',
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
                'message' => 'Kamu tidak punya izin menghapus tugas ini.',
            ];
        }

        // Check for explicit success indicators
        $successIndicators = [
            'berhasil dihapus' => 'Tugas berhasil dihapus.',
            'hapus berhasil' => 'Tugas berhasil dihapus.',
            'terhapus' => 'Tugas berhasil dihapus.',
            'deleted' => 'Tugas berhasil dihapus.',
            'sukses' => 'Hapus berhasil.',
            'berhasil' => 'Hapus berhasil.',
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
                    'message' => 'Tugas berhasil dihapus.',
                ];
            }
        }

        // No clear indicator - be conservative
        return [
            'success' => false,
            'code' => 'unknown',
            'message' => 'Status hapus belum bisa dipastikan. Periksa di halaman tugas.',
        ];
    }

    /**
     * The delete link of this meeting's submission. A page that also lists
     * the student's other uploads must not hand out their delete links.
     *
     * The `act=send` page is already scoped to this course and discussion,
     * so the link is matched on its meeting number. `idx` is usually the
     * discussion id but that is not guaranteed; it only decides between
     * several links for the same meeting, and when none of those carries
     * this discussion the target is ambiguous and nothing is returned.
     *
     * @return array{id: string, idx: string, pertemuan: string}|null
     */
    private function extractAssignmentDeletePayload(Crawler $crawler, int $discussionId, int $meetingNumber): ?array
    {
        $candidates = [];
        $deleteLinkCount = 0;

        foreach ($crawler->filter('a[href*="jobdel"]') as $node) {
            $href = html_entity_decode((string) ((new Crawler($node))->attr('href') ?? ''), ENT_QUOTES, 'UTF-8');
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

            $deleteLinkCount++;
            $submissionId = $query['id'] ?? null;
            $linkDiscussionId = $query['idx'] ?? null;
            $linkMeetingNumber = $query['pertemuan'] ?? null;

            if (! is_scalar($submissionId) || ! is_scalar($linkDiscussionId) || ! is_scalar($linkMeetingNumber)) {
                continue;
            }

            if ((string) $linkMeetingNumber === '' || (int) $linkMeetingNumber !== $meetingNumber) {
                continue;
            }

            $candidates[] = [
                'id' => (string) $submissionId,
                'idx' => (string) $linkDiscussionId,
                'pertemuan' => (string) $linkMeetingNumber,
            ];
        }

        if ($candidates === []) {
            if ($deleteLinkCount > 0) {
                Log::info('SIPANDAI assignment page lists delete links, none for this meeting', [
                    'discussion_id' => $discussionId,
                    'meeting_number' => $meetingNumber,
                    'delete_links' => $deleteLinkCount,
                ]);
            }

            return null;
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        foreach ($candidates as $candidate) {
            if ($candidate['idx'] === (string) $discussionId) {
                return $candidate;
            }
        }

        Log::info('SIPANDAI assignment page lists several delete links for this meeting, none for this discussion', [
            'discussion_id' => $discussionId,
            'meeting_number' => $meetingNumber,
            'delete_links' => count($candidates),
        ]);

        return null;
    }

    /**
     * The file the student submitted for this meeting. Submitted files live
     * in `files/tugasmhs/` and are named `<courses_id>-<meeting>-<nim>-…`;
     * a name for another course or meeting is not this submission.
     *
     * @return array{href: string, text: string}|null
     */
    private function extractAssignmentUploadedFileInfo(Crawler $crawler, int $courseId, int $meetingNumber): ?array
    {
        foreach ($crawler->filter('a[href]') as $node) {
            $linkCrawler = new Crawler($node);
            $href = trim((string) ($linkCrawler->attr('href') ?? ''));
            $text = $this->cleanText($linkCrawler->text());

            if (! Str::contains(strtolower($href), 'files/tugasmhs/')) {
                continue;
            }

            $fileName = basename((string) parse_url($href, PHP_URL_PATH));

            if (preg_match('/^(\d+)-(\d+)-/', $fileName, $matches) === 1
                && ((int) $matches[1] !== $courseId || (int) $matches[2] !== $meetingNumber)) {
                continue;
            }

            return [
                'href' => $href,
                'text' => $text !== '' ? $text : $fileName,
            ];
        }

        return null;
    }

    private function resolveDownloadFilename(ResponseInterface $response, string $downloadUrl, ?string $suggestedName = null): string
    {
        $sanitizedSuggestedName = $this->sanitizeDownloadFilename($suggestedName);

        if ($sanitizedSuggestedName !== null) {
            return $sanitizedSuggestedName;
        }

        $contentDisposition = $response->getHeaderLine('Content-Disposition');

        if ($contentDisposition !== '') {
            if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $contentDisposition, $matches) === 1) {
                return $this->sanitizeDownloadFilename(rawurldecode($matches[1])) ?? 'download';
            }

            if (preg_match('/filename=\"?([^\";]+)\"?/i', $contentDisposition, $matches) === 1) {
                return $this->sanitizeDownloadFilename($matches[1]) ?? 'download';
            }
        }

        $path = rawurldecode((string) parse_url($downloadUrl, PHP_URL_PATH));

        return $this->sanitizeDownloadFilename(basename($path)) ?? 'download';
    }

    private function sanitizeDownloadFilename(?string $filename): ?string
    {
        if (! is_string($filename) || trim($filename) === '') {
            return null;
        }

        $sanitized = basename(str_replace('\\', '/', trim($filename)));

        return $sanitized !== '' ? $sanitized : null;
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
