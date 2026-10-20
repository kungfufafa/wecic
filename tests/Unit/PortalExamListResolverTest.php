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
