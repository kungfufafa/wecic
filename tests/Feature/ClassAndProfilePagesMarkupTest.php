<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Markup of the class list, class detail and profile pages. Their data is
 * rendered client side, so these assert the page script and attributes.
 */
class ClassAndProfilePagesMarkupTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_class_detail_page_calls_its_assignment_card_the_submitted_assignments(): void
    {
        $this->mockValidCampusSession('token-class-show');

        $this->withSession(['campus_session_token' => 'token-class-show'])
            ->get('/class/2265?dosen_id=5240&course_id=8810')
            ->assertOk()
            ->assertSee('Tugas terkumpul')
            ->assertSee('Belum ada tugas yang kamu kumpulkan.')
            ->assertSee('Tugas yang belum dikumpulkan dan tenggatnya ada di halaman pertemuan.')
            ->assertDontSee("renderEmptyState('Belum ada tugas.'", false);
    }

    public function test_the_class_detail_page_hides_the_study_actions_for_an_unknown_class(): void
    {
        $this->mockValidCampusSession('token-class-unknown');

        $this->withSession(['campus_session_token' => 'token-class-unknown'])
            ->get('/class/abc')
            ->assertOk()
            ->assertSee('id="class-study"', false)
            ->assertSee("document.getElementById('class-study').classList.add('hidden');", false)
            ->assertSee("document.getElementById('class-study').classList.remove('hidden');", false);
    }

    public function test_the_class_grid_and_day_filter_are_keyboard_operable_and_the_modal_is_labelled(): void
    {
        $this->mockValidCampusSession('token-class-index');

        $this->withSession(['campus_session_token' => 'token-class-index'])
            ->get('/class')
            ->assertOk()
            ->assertSee('role="dialog" aria-modal="true" aria-labelledby="modal-class-title"', false)
            ->assertSee('<div role="button" tabindex="0" aria-pressed=', false)
            ->assertSee('<div role="button" tabindex="0" aria-haspopup="dialog"', false)
            ->assertSee("event.key === 'Enter' || event.key === ' '", false)
            ->assertSee('modalCloseBtn?.focus();', false)
            ->assertSee('modalReturnFocus.focus();', false)
            ->assertDontSee("el.addEventListener('click', () => {\n                        const classId", false);
    }

    public function test_the_profile_page_never_invents_a_status_and_shows_an_error_state_when_nothing_loaded(): void
    {
        $this->mockValidCampusSession('token-profile');

        $this->withSession(['campus_session_token' => 'token-profile'])
            ->get('/profile')
            ->assertOk()
            ->assertSee("quickStatus.textContent = personal.status || '-';", false)
            ->assertDontSee("personal.status || 'Aktif'", false)
            ->assertSee("app.mountErrorState(document.getElementById('profile-personal'), 'Profil gagal dimuat.'", false)
            ->assertSee('if (profileRendered) {', false);
    }

    private function mockValidCampusSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}
