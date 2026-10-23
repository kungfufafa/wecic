<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

/**
 * The password form is only ever sent by its script, as JSON: a native
 * submit (before the script is ready, or when the session check failed)
 * would put all three passwords in the URL, the history and the logs.
 */
class PortalPasswordPageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_password_form_can_never_submit_itself(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with('token-password-page')->andReturnTrue();
            $mock->shouldReceive('getSession')->andReturnNull();
        }));

        $html = $this->withSession(['campus_session_token' => 'token-password-page'])
            ->get('/password')
            ->assertOk()
            ->getContent();

        $crawler = new Crawler((string) $html);
        $form = $crawler->filter('#form-change-password');

        $this->assertCount(1, $form);
        $this->assertSame('post', $form->attr('method'));
        $this->assertSame('javascript:void(0)', $form->attr('action'));
        // Disabled until the session check passed, so Enter cannot submit it either.
        $this->assertNotNull($crawler->filter('#btn-submit')->attr('disabled'));
        $this->assertStringContainsString('btnSubmit.disabled = !ready;', (string) $html);
    }

    public function test_the_password_page_offers_no_data_sync(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with('token-password-page')->andReturnTrue();
            $mock->shouldReceive('getSession')->andReturnNull();
        }));

        // Nothing on the page comes from a dataset, so "Sinkronkan" has nothing to sync.
        $this->withSession(['campus_session_token' => 'token-password-page'])
            ->get('/password')
            ->assertOk()
            ->assertDontSee('data-sync-refresh', false);
    }
}
