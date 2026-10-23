<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SipandaiMeetingDetailRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_meeting_link_with_a_non_numeric_id_is_rejected_in_indonesian(): void
    {
        $this->mockValidCampusSession('token-meeting');
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractClassDetail');
        }));

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->getJson('/api/meeting/abc?courses_id=8861')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Parameter meeting_id harus berupa angka.');
    }

    public function test_a_meeting_link_without_the_course_explains_where_to_open_it(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->getJson('/api/meeting/445')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Parameter courses_id wajib diisi. Buka pertemuan dari halaman Kelas.');
    }

    public function test_a_complete_meeting_link_passes_validation(): void
    {
        $this->mockValidCampusSession('token-meeting');
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')->once()->andReturn(['meeting_info' => ['title' => 'Pertemuan 1']]);
        }));

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->getJson('/api/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertJsonPath('meta.courses_id', '8861');
    }

    private function mockValidCampusSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')->with($token)->andReturnTrue();
            })
        );
    }
}
