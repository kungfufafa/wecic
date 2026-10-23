<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Web routes check CSRF with the app's middleware, which also enforces the
 * token in feature tests (the framework's own skips it there).
 */
class WebCsrfProtectionTest extends TestCase
{
    public function test_the_web_group_uses_the_app_csrf_middleware(): void
    {
        $web = $this->app->make(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(VerifyCsrfTokenMiddleware::class, $web);
        $this->assertNotContains(ValidateCsrfToken::class, $web);
    }

    public function test_a_web_post_without_a_token_is_refused(): void
    {
        Route::middleware('web')->post('/__csrf-probe', fn (): string => 'diterima');

        $this->post('/__csrf-probe')->assertStatus(419);
    }

    public function test_a_web_post_with_the_session_token_is_accepted(): void
    {
        Route::middleware('web')->post('/__csrf-probe', fn (): string => 'diterima');

        $this->withSession(['_token' => 'token-uji'])
            ->post('/__csrf-probe', ['_token' => 'token-uji'])
            ->assertOk()
            ->assertSee('diterima');
    }
}
