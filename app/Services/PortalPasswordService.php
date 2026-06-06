<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalScrapingException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class PortalPasswordService extends PortalScrapingService
{
    /**
     * Inspect the upstream password change form.
     *
     * @return array{action_url: string, method: string, fields: array<string, array{type: string, required: bool, has_value: bool}>, password_fields: list<string>, extracted_at: string}
     */
    public function inspectPasswordForm(string $sessionToken): array
    {
        $this->setSession($sessionToken);

        $formUrl = PortalEndpointRegistry::url('password_form');
        $html = $this->get($formUrl);
        $form = $this->resolvePasswordForm($html);
        $formMeta = $this->extractFormMetadata($form, $formUrl);

        return [
            'action_url' => $formMeta['action_url'],
            'method' => $formMeta['method'],
            'fields' => $this->redactFieldValues($formMeta['fields']),
            'password_fields' => $this->passwordFieldNames($formMeta['fields']),
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Submit a password change request to the upstream portal.
     *
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function changePassword(
        string $sessionToken,
        string $currentPassword,
        string $newPassword,
        string $newPasswordConfirmation
    ): array {
        $this->setSession($sessionToken);

        $formUrl = PortalEndpointRegistry::url('password_form');
        $submitUrl = PortalEndpointRegistry::url('password_submit');
        $formHtml = $this->get($formUrl);
        $form = $this->resolvePasswordForm($formHtml);
        $formMeta = $this->extractFormMetadata($form, $formUrl);
        $actionUrl = $formMeta['action_url'] !== '' ? $formMeta['action_url'] : $submitUrl;
        $payload = $this->buildPasswordPayload(
            $formMeta['fields'],
            $currentPassword,
            $newPassword,
            $newPasswordConfirmation
        );

        $response = $this->dispatchPasswordRequest(
            strtoupper($formMeta['method']) === 'GET' ? 'GET' : 'POST',
            $actionUrl,
            $payload,
            $formUrl
        );

        $responseHtml = (string) $response->getBody();
        $this->validateResponse($responseHtml, $actionUrl, allowShortResponse: true);

        return $this->buildPasswordChangeResult($responseHtml, $actionUrl, array_keys($payload));
    }

    private function resolvePasswordForm(string $html): Crawler
    {
        $crawler = $this->createCrawler($html);
        $forms = $crawler->filter('form');

        if ($forms->count() === 0) {
            throw PortalScrapingException::elementNotFound('form', 'password change page');
        }

        foreach ($forms as $node) {
            $form = new Crawler($node);
            $action = strtolower((string) ($form->attr('action') ?? ''));
            $text = strtolower($this->cleanText($form->text()));

            if (Str::contains($action, 'passwordact') || Str::contains($text, ['password', 'konfirm'])) {
                return $form;
            }
        }

        return $forms->first();
    }

    /**
     * @return array{action_url: string, method: string, fields: array<string, array{type: string, value: string, required: bool}>}
     */
    private function extractFormMetadata(Crawler $form, string $baseUrl): array
    {
        $fields = [];

        $form->filter('input, textarea, select')->each(function (Crawler $field) use (&$fields): void {
            $name = $field->attr('name');

            if ($name === null || trim($name) === '') {
                return;
            }

            $tag = strtolower($field->nodeName());
            $type = strtolower((string) ($field->attr('type') ?? ($tag === 'select' ? 'select' : 'text')));

            if (in_array($type, ['submit', 'button', 'reset', 'file'], true)) {
                return;
            }

            $fields[$name] = [
                'type' => $type,
                'value' => (string) ($field->attr('value') ?? ''),
                'required' => $field->attr('required') !== null,
            ];
        });

        return [
            'action_url' => $this->resolveActionUrl((string) ($form->attr('action') ?? ''), $baseUrl),
            'method' => strtoupper((string) ($form->attr('method') ?? 'POST')),
            'fields' => $fields,
        ];
    }

    private function resolveActionUrl(string $action, string $baseUrl): string
    {
        return PortalUrlGuard::resolve($action !== '' ? $action : PortalEndpointRegistry::url('password_submit'), $baseUrl);
    }

    /**
     * @param  array<string, array{type: string, value: string, required: bool}>  $fields
     * @return array<string, string>
     */
    private function buildPasswordPayload(
        array $fields,
        string $currentPassword,
        string $newPassword,
        string $newPasswordConfirmation
    ): array {
        $payload = [];

        foreach ($fields as $name => $field) {
            $payload[$name] = $field['value'];
        }

        $payload[$this->resolveFieldName($fields, ['data[passwordold]', 'passwordold', 'old_password', 'password_old'])] = $currentPassword;
        $payload[$this->resolveFieldName($fields, ['data[password]', 'password', 'new_password'])] = $newPassword;
        $payload[$this->resolveFieldName($fields, ['data[konfirm]', 'konfirm', 'password_confirmation', 'confirm_password'])] = $newPasswordConfirmation;

        return $payload;
    }

    /**
     * @param  array<string, array{type: string, value: string, required: bool}>  $fields
     * @param  list<string>  $candidates
     */
    private function resolveFieldName(array $fields, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $fields)) {
                return $candidate;
            }
        }

        foreach (array_keys($fields) as $fieldName) {
            $normalized = strtolower($fieldName);

            foreach ($candidates as $candidate) {
                if (Str::contains($normalized, strtolower(str_replace(['data[', ']'], '', $candidate)))) {
                    return $fieldName;
                }
            }
        }

        return $candidates[0];
    }

    /**
     * @param  array<string, array{type: string, value: string, required: bool}>  $fields
     * @return array<string, array{type: string, required: bool, has_value: bool}>
     */
    private function redactFieldValues(array $fields): array
    {
        return collect($fields)
            ->map(fn (array $field): array => [
                'type' => $field['type'],
                'required' => $field['required'],
                'has_value' => $field['value'] !== '',
            ])
            ->all();
    }

    /**
     * @param  array<string, array{type: string, value: string, required: bool}>  $fields
     * @return list<string>
     */
    private function passwordFieldNames(array $fields): array
    {
        return array_values(array_filter(
            array_keys($fields),
            static fn (string $name): bool => str_contains(strtolower($name), 'password')
                || str_contains(strtolower($name), 'konfirm')
        ));
    }

    /**
     * @param  array<string, string>  $payload
     */
    protected function dispatchPasswordRequest(string $method, string $url, array $payload, string $referer): ResponseInterface
    {
        $options = [
            RequestOptions::COOKIES => $this->cookieJar,
            RequestOptions::HEADERS => [
                'Referer' => $referer,
                'Origin' => config('portal.base_url'),
            ],
        ];

        if ($method === 'GET') {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator.http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        } else {
            $options[RequestOptions::FORM_PARAMS] = $payload;
        }

        return $this->client->request($method, $url, $options);
    }

    /**
     * @param  list<string>  $sentFields
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    private function buildPasswordChangeResult(string $html, string $actionUrl, array $sentFields): array
    {
        $plainText = strtolower($this->cleanText(strip_tags($html)));
        $alerts = $this->extractAlertMessages($html);
        $combinedText = strtolower($plainText.' '.implode(' ', $alerts));
        $failureIndicators = [
            'password lama salah',
            'password lama tidak',
            'konfirmasi',
            'tidak sesuai',
            'gagal',
            'error',
            'salah',
            'failed',
        ];

        foreach ($failureIndicators as $indicator) {
            if (str_contains($combinedText, $indicator)) {
                return [
                    'success' => false,
                    'message' => $alerts[0] ?? 'Password change failed.',
                    'data' => [
                        'action_url' => $actionUrl,
                        'sent_fields' => $sentFields,
                        'alerts' => $alerts,
                    ],
                ];
            }
        }

        $successIndicators = [
            'password berhasil',
            'berhasil',
            'sukses',
            'success',
            'disimpan',
            'tersimpan',
        ];

        foreach ($successIndicators as $indicator) {
            if (str_contains($combinedText, $indicator)) {
                return [
                    'success' => true,
                    'message' => $alerts[0] ?? 'Password changed successfully.',
                    'data' => [
                        'action_url' => $actionUrl,
                        'sent_fields' => $sentFields,
                        'alerts' => $alerts,
                    ],
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'Password change status could not be determined. Please verify manually.',
            'data' => [
                'action_url' => $actionUrl,
                'sent_fields' => $sentFields,
                'alerts' => $alerts,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function extractAlertMessages(string $html): array
    {
        $crawler = $this->createCrawler($html);
        $messages = [];

        $crawler->filter('.alert, .error, .success, .message, .notification, .notif')->each(function (Crawler $node) use (&$messages): void {
            $text = $this->cleanText($node->text());

            if ($text !== '') {
                $messages[] = $text;
            }
        });

        return array_values(array_unique($messages));
    }
}
