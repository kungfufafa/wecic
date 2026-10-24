<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalOutboxItem;
use App\Services\PortalSessionManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every error under /api (each version) is JSON in the API's envelope,
 * also for a client that sends no `Accept: application/json` (the mobile
 * app) and for errors no controller answers, with the words the web app
 * shows for the framework's English defaults. Requests here send no Accept
 * header unless a test says otherwise.
 */
class ApiJsonErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_ERROR = 'Server sedang bermasalah. Coba lagi sebentar lagi.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function apiPrefixes(): array
    {
        return [
            'unversioned (web app)' => ['/api'],
            'v1 (mobile app)' => ['/api/v1'],
        ];
    }

    #[DataProvider('apiPrefixes')]
    public function test_an_unknown_endpoint_is_a_json_404(string $prefix): void
    {
        $response = $this->get($prefix.'/no-such-endpoint')->assertNotFound();

        $this->assertJsonEnvelope($response, 'Endpoint tidak ditemukan.');
    }

    #[DataProvider('apiPrefixes')]
    public function test_a_method_the_route_does_not_take_is_a_json_405(string $prefix): void
    {
        $response = $this->put($prefix.'/auth/session')->assertMethodNotAllowed();

        $this->assertJsonEnvelope($response, 'Metode PUT tidak didukung endpoint ini.');
    }

    public function test_a_spent_rate_limit_is_a_json_429_that_says_when_to_retry(): void
    {
        Route::middleware(['api', 'throttle:1,1'])->get('api/v1/testing/throttled', fn (): array => ['status' => 'success']);

        $this->get('/api/v1/testing/throttled')->assertOk();
        $response = $this->get('/api/v1/testing/throttled')->assertTooManyRequests()->assertHeader('Retry-After', '60');

        $this->assertJsonEnvelope($response, 'Terlalu sering mencoba. Coba lagi dalam 1 menit.');
    }

    public function test_a_cookie_request_without_a_csrf_token_is_a_json_419(): void
    {
        $response = $this->post('/api/v1/auth/login', ['username' => '20990000501', 'password' => 'rahasia'])->assertStatus(419);

        $this->assertJsonEnvelope($response, 'Halaman sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }

    public function test_a_body_over_the_server_limit_is_a_json_413(): void
    {
        $response = $this->call('POST', '/api/v1/assignment', server: ['CONTENT_LENGTH' => (string) PHP_INT_MAX])->assertStatus(413);

        $this->assertJsonEnvelope($response, 'Ukuran data yang dikirim melebihi batas server.');
    }

    public function test_an_uncaught_exception_is_a_json_500_that_tells_nothing_about_itself(): void
    {
        Route::middleware('api')->get('api/v1/testing/broken', fn () => throw new \RuntimeException('secret table name'));

        $response = $this->get('/api/v1/testing/broken')->assertInternalServerError();

        $this->assertJsonEnvelope($response, self::SERVER_ERROR);
        $this->assertStringNotContainsString('secret table name', (string) $response->getContent());
    }

    public function test_debug_mode_keeps_the_exception_details_in_the_envelope(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('api/v1/testing/broken', fn () => throw new \RuntimeException('secret table name'));

        $this->get('/api/v1/testing/broken')
            ->assertInternalServerError()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'secret table name')
            ->assertJsonPath('exception', \RuntimeException::class);
    }

    public function test_a_missing_model_is_a_json_404_without_the_model_class(): void
    {
        Route::middleware('api')->get('api/v1/testing/missing', fn () => throw (new ModelNotFoundException)->setModel(PortalOutboxItem::class, [5]));

        $response = $this->get('/api/v1/testing/missing')->assertNotFound();

        $this->assertJsonEnvelope($response, 'Data yang diminta tidak ditemukan.');
        $this->assertStringNotContainsString('PortalOutboxItem', (string) $response->getContent());
    }

    public function test_a_message_app_code_gave_is_kept_and_an_empty_or_framework_one_is_reworded(): void
    {
        Route::middleware('api')->get('api/v1/testing/conflict', fn () => abort(409, 'Data sedang diperbarui.'));
        Route::middleware('api')->get('api/v1/testing/forbidden', fn () => abort(403));
        // What maintenance mode (`artisan down`) throws.
        Route::middleware('api')->get('api/v1/testing/maintenance', fn () => abort(503, 'Service Unavailable'));

        $this->assertJsonEnvelope($this->get('/api/v1/testing/conflict')->assertConflict(), 'Data sedang diperbarui.');
        $this->assertJsonEnvelope($this->get('/api/v1/testing/forbidden')->assertForbidden(), 'Kamu tidak punya akses ke data ini.');
        $this->assertJsonEnvelope($this->get('/api/v1/testing/maintenance')->assertServiceUnavailable(), self::SERVER_ERROR);
    }

    public function test_a_validation_error_outside_a_form_request_keeps_its_errors(): void
    {
        Route::middleware('api')->get('api/v1/testing/invalid', fn () => throw ValidationException::withMessages(['nim' => 'NIM tidak valid.']));

        $this->get('/api/v1/testing/invalid')
            ->assertUnprocessable()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'NIM tidak valid.',
                'errors' => ['nim' => ['NIM tidak valid.']],
            ]);
    }

    public function test_an_error_that_already_has_the_envelope_is_left_alone(): void
    {
        $this->post('/api/v1/auth/token', [])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['status', 'message', 'errors' => ['username', 'password']]);

        $this->get('/api/v1/notifications')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Sesi kampus tidak ditemukan. Silakan masuk lagi.')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    #[DataProvider('apiPrefixes')]
    public function test_a_download_opened_in_a_tab_keeps_its_html_error_page(string $prefix): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andThrow(new \RuntimeException('Cache store unavailable'));
        }));

        $response = $this->withSession(['campus_session_token' => 'campus-token'])
            ->get($prefix.'/support/download?path=../files/modul/sample.pdf', ['Accept' => 'text/html'])
            ->assertServiceUnavailable();

        $this->assertStringStartsWith('text/html', (string) $response->headers->get('content-type'));
    }

    public function test_web_pages_keep_html_error_pages(): void
    {
        $response = $this->get('/no-such-page')->assertNotFound();

        $this->assertStringStartsWith('text/html', (string) $response->headers->get('content-type'));
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function assertJsonEnvelope(TestResponse $response, string $message): void
    {
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('content-type'));
        $response->assertExactJson(['status' => 'error', 'message' => $message]);
    }
}
