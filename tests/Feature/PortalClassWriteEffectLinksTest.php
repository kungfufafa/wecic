<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * K13: a portal link with a write effect (act=jobdel, act=kesesuaian) never
 * reaches the browser from the class, meeting and flow endpoints; read links
 * (files, the upload form) stay.
 */
class PortalClassWriteEffectLinksTest extends TestCase
{
    private const DELETE_HREF = '?mod=sipandai&act=jobdel&id=126663&idx=33317&pertemuan=5';

    private const FILE_HREF = '../files/tugasmhs/9198-5-20990000001-a14.pdf';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_class_detail_drops_the_assignment_delete_link_and_keeps_the_file_link(): void
    {
        $this->mockValidCampusSession('token-class-links');

        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->andReturn([
                    'content_type' => 'class_info',
                    'course_info' => ['name' => 'Data Governance'],
                    'assignment_history' => ['assignments' => [$this->submittedAssignment()]],
                ]);
        }));

        $response = $this->withSession(['campus_session_token' => 'token-class-links'])
            ->getJson('/api/class/1688?dosen_id=2744&course_id=9198')
            ->assertOk()
            ->assertJsonPath('class_detail.assignment_history.assignments.0.file_info.href', self::FILE_HREF)
            ->assertJsonPath('class_detail.assignment_history.assignments.0.delete_info.href', null);

        $this->assertStringNotContainsString('jobdel', (string) $response->getContent());
    }

    public function test_meeting_detail_drops_the_conformity_answer_links(): void
    {
        $this->mockValidCampusSession('token-meeting-links');

        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->andReturn([
                    'found' => true,
                    'meeting_info' => ['breadcrumb' => 'Beranda > Kelas > Pertemuan 5'],
                    'conformity_info' => [
                        'description' => 'Kesesuaian Materi',
                        'yes_button' => ['href' => '?mod=student&act=kesesuaian&id=33317&jawab=1', 'text' => 'Ya'],
                        'no_button' => ['href' => '?mod=student&act=kesesuaian&id=33317&jawab=0', 'text' => 'Tidak'],
                    ],
                ]);
        }));

        $response = $this->withSession(['campus_session_token' => 'token-meeting-links'])
            ->getJson('/api/meeting/33317?courses_id=9198')
            ->assertOk()
            ->assertJsonPath('class_detail.conformity_info.yes_button.href', null)
            ->assertJsonPath('class_detail.conformity_info.yes_button.text', 'Ya');

        $this->assertStringNotContainsString('act=kesesuaian', (string) $response->getContent());
    }

    public function test_the_flow_never_builds_an_assignment_delete_link(): void
    {
        $this->mockValidCampusSession('token-flow-links');

        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')
                ->once()
                ->andReturn([
                    'course_info' => ['name' => 'Data Governance'],
                    'assignment_history' => ['assignments' => [$this->submittedAssignment()]],
                ]);
        }));

        $response = $this->withSession(['campus_session_token' => 'token-flow-links'])
            ->getJson('/api/lms/flow?id=1688&dosen_id=2744&course_id=9198&pertemuan=5&submission_id=126663')
            ->assertOk()
            ->assertJsonMissingPath('data.links.assignment_delete')
            ->assertJsonPath('data.course_detail.assignment_history.assignments.0.delete_info.href', null);

        $this->assertArrayHasKey('assignment_form', $response->json('data.links'));
        $this->assertStringNotContainsString('jobdel', (string) $response->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function submittedAssignment(): array
    {
        return [
            'no' => '1.',
            'date' => 'Selasa, 21 April 2026',
            'meeting' => '5',
            'score' => '0',
            'file_info' => ['href' => self::FILE_HREF, 'text' => 'Open File'],
            'delete_info' => ['href' => self::DELETE_HREF, 'text' => 'Hapus'],
        ];
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
