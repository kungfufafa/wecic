<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalExamListResolver;
use App\Services\SipandaiSubmissionService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalExamListResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_exam_rows_with_a_portal_upload_link_can_be_uploaded(): void
    {
        $resolver = $this->makeResolver([
            [
                'id' => 9186,
                'course_code' => 'UC01022004',
                'course_name' => 'Teknologi dan trend E-commerce',
                'schedule' => '',
                'status' => '',
                'upload_url' => '?mod=sipandai&act=sendujian&id=9186',
            ],
        ]);

        $result = $resolver->resolve('token-exam');

        $this->assertSame('exam_page', $result['source']);
        $this->assertTrue($result['exams'][0]['can_upload']);
        $this->assertSame('?mod=sipandai&act=sendujian&id=9186', $result['exams'][0]['upload_url']);
    }

    public function test_class_schedule_fallback_rows_cannot_be_uploaded(): void
    {
        $resolver = $this->makeResolver([], $this->sipandaiClasses());

        $result = $resolver->resolve('token-exam');

        $this->assertSame(8810, $result['exams'][0]['id']);
        $this->assertSame('class_schedule', $result['exams'][0]['source']);
        $this->assertFalse($result['exams'][0]['can_upload']);
        $this->assertNull($result['exams'][0]['upload_url']);
    }

    public function test_class_schedule_fallback_after_exam_page_failure_cannot_be_uploaded(): void
    {
        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getExamList')->once()->andThrow(new \RuntimeException('Portal timeout'));
        });
        $classesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->andReturn(['classes' => $this->sipandaiClasses()]);
        });

        $result = (new PortalExamListResolver($submissionService, $classesExtractor))->resolve('token-exam');

        $this->assertSame('class_schedule_fallback', $result['source']);
        $this->assertFalse($result['exams'][0]['can_upload']);
        $this->assertNull($result['exams'][0]['upload_url']);
    }

    public function test_exam_rows_given_a_class_id_cannot_be_uploaded(): void
    {
        $resolver = $this->makeResolver([
            [
                'id' => null,
                'course_code' => 'UC01024011',
                'course_name' => 'Bahasa Indonesia',
                'schedule' => 'Senin 09:30 - 11:00',
                'status' => 'Terjadwal',
                'upload_url' => null,
            ],
        ], $this->sipandaiClasses());

        $exam = $resolver->resolve('token-exam')['exams'][0];

        $this->assertSame(8810, $exam['id']);
        $this->assertSame('exam_list_with_class_id', $exam['source']);
        $this->assertFalse($exam['can_upload']);
        $this->assertNull($exam['upload_url']);
    }

    public function test_exam_rows_get_the_class_id_of_their_own_course_when_codes_share_a_prefix(): void
    {
        $resolver = $this->makeResolver([
            $this->examRowWithoutId('SI033017', 'Statistik Inferensial'),
            $this->examRowWithoutId('SI033019', 'Analisis dan Implementasi Big Data Science'),
            $this->examRowWithoutId('DS052001', 'Teknik Pengolahan Data'),
            $this->examRowWithoutId('DS052002', 'Data Governance'),
        ], [
            $this->sipandaiClass('SI033017', 'Statistik Inferensial', '9501'),
            $this->sipandaiClass('SI033019', 'Analisis dan Implementasi Big Data Science', '9524'),
            $this->sipandaiClass('DS052001', 'Teknik Pengolahan Data', '9480'),
            $this->sipandaiClass('DS052002', 'Data Governance', '9496'),
        ]);

        $ids = array_column($resolver->resolve('token-exam')['exams'], 'id', 'course_code');

        $this->assertSame([
            'SI033017' => 9501,
            'SI033019' => 9524,
            'DS052001' => 9480,
            'DS052002' => 9496,
        ], $ids);
    }

    public function test_exam_rows_matching_two_classes_by_name_keep_no_id(): void
    {
        $resolver = $this->makeResolver([
            $this->examRowWithoutId('', 'Statistik Inferensial'),
        ], [
            $this->sipandaiClass('SI033017', 'Statistik Inferensial', '9501'),
            $this->sipandaiClass('SI033018', 'Statistik Inferensial', '9502'),
        ]);

        $exam = $resolver->resolve('token-exam')['exams'][0];

        $this->assertNull($exam['id']);
        $this->assertFalse($exam['can_upload']);
    }

    /**
     * @return array<string, mixed>
     */
    private function examRowWithoutId(string $code, string $name): array
    {
        return [
            'id' => null,
            'course_code' => $code,
            'course_name' => $name,
            'schedule' => 'Senin 09:30 - 11:00',
            'status' => 'Terjadwal',
            'upload_url' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sipandaiClass(string $code, string $name, string $courseId): array
    {
        return [
            'schedule' => ['day' => 'Senin', 'formatted_time' => '09:30 - 11:00'],
            'course_info' => ['code' => $code, 'name' => $name],
            'join_link' => ['params' => ['id' => '1'.$courseId, 'dosen_id' => '5240', 'course_id' => $courseId]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $examRows
     * @param  list<array<string, mixed>>|null  $classes
     */
    private function makeResolver(array $examRows, ?array $classes = null): PortalExamListResolver
    {
        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($examRows): void {
            $mock->shouldReceive('getExamList')->once()->with('token-exam')->andReturn($examRows);
        });
        $classesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock) use ($classes): void {
            if ($classes === null) {
                $mock->shouldNotReceive('extractSipandaiClassesData');

                return;
            }

            $mock->shouldReceive('extractSipandaiClassesData')->once()->with('token-exam')->andReturn(['classes' => $classes]);
        });

        return new PortalExamListResolver($submissionService, $classesExtractor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sipandaiClasses(): array
    {
        return [
            [
                'schedule' => [
                    'day' => 'Senin',
                    'formatted_time' => '09:30 - 11:00',
                ],
                'course_info' => [
                    'code' => 'UC01024011',
                    'name' => 'Bahasa Indonesia',
                ],
                'join_link' => [
                    'params' => [
                        'id' => '2265',
                        'dosen_id' => '5240',
                        'course_id' => '8810',
                    ],
                ],
            ],
        ];
    }
}
