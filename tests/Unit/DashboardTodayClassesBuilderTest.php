<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DashboardTodayClassesBuilder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DashboardTodayClassesBuilderTest extends TestCase
{
    public function test_it_builds_today_classes_with_room_info_and_active_state(): void
    {
        $builder = new DashboardTodayClassesBuilder;

        $result = $builder->build(
            [
                [
                    'schedule' => [
                        'day' => 'Senin',
                        'start_time' => '09:30:00',
                        'end_time' => '11:00:00',
                        'time_range' => '09:30:00-11:00:00',
                        'formatted_time' => '09:30 - 11:00',
                    ],
                    'course_info' => [
                        'code' => 'UC01024011',
                        'name' => 'Bahasa Indonesia',
                    ],
                    'lecturer_info' => [
                        'name' => 'Dosen Contoh Tiga, M.Pd',
                    ],
                ],
                [
                    'schedule' => [
                        'day' => 'Selasa',
                        'start_time' => '12:30:00',
                        'end_time' => '14:00:00',
                        'time_range' => '12:30:00-14:00:00',
                        'formatted_time' => '12:30 - 14:00',
                    ],
                    'course_info' => [
                        'code' => 'SI053025',
                        'name' => 'Perancangan Aplikasi Mobile',
                    ],
                    'lecturer_info' => [
                        'name' => 'Dosen Contoh Satu, M.Kom.',
                    ],
                ],
            ],
            [
                [
                    'schedule' => [
                        'day' => 'Senin',
                        'start_time' => '09:30:00',
                        'end_time' => '11:00:00',
                        'time_range' => '09:30:00-11:00:00',
                    ],
                    'course_info' => [
                        'code' => 'UC01024011',
                    ],
                    'class_room_info' => [
                        'class_name' => 'SI-REG-1',
                        'room' => 'Ruang 201',
                    ],
                ],
            ],
            CarbonImmutable::create(2026, 4, 13, 10, 0, 0, 'Asia/Jakarta')
        );

        $this->assertSame('2026-04-13', $result['date']);
        $this->assertSame('Senin', $result['day']);
        $this->assertCount(1, $result['classes']);
        $this->assertSame('Bahasa Indonesia', $result['classes'][0]['course_name']);
        $this->assertSame('SI-REG-1', $result['classes'][0]['class_name']);
        $this->assertSame('Ruang 201', $result['classes'][0]['room']);
        $this->assertSame('09:30 - 11:00', $result['classes'][0]['schedule']['time']);
        $this->assertTrue($result['classes'][0]['active_now']);
    }

    public function test_it_keeps_today_classes_even_when_room_info_is_missing(): void
    {
        $builder = new DashboardTodayClassesBuilder;

        $result = $builder->build(
            [
                [
                    'schedule' => [
                        'day' => 'Jumat',
                        'start_time' => '13:00:00',
                        'end_time' => '14:40:00',
                        'time_range' => '13:00:00-14:40:00',
                        'formatted_time' => '13:00 - 14:40',
                    ],
                    'course_info' => [
                        'code' => 'IF204',
                        'name' => 'Basis Data',
                    ],
                    'lecturer_info' => [
                        'name' => 'Dosen Pengampu',
                    ],
                ],
            ],
            [],
            CarbonImmutable::create(2026, 4, 17, 8, 0, 0, 'Asia/Jakarta')
        );

        $this->assertCount(1, $result['classes']);
        $this->assertSame('Basis Data', $result['classes'][0]['course_name']);
        $this->assertNull($result['classes'][0]['room']);
        $this->assertFalse($result['classes'][0]['active_now']);
    }

    public function test_a_stored_schedule_is_reordered_by_the_class_in_session_at_read_time(): void
    {
        $builder = new DashboardTodayClassesBuilder;
        $built = $builder->build(
            [$this->sipandaiClass('Senin', '08:00:00', '09:40:00', 'IF101', 'Algoritma'), $this->sipandaiClass('Senin', '20:00:00', '21:30:00', 'IF202', 'Jaringan')],
            [],
            CarbonImmutable::create(2026, 4, 13, 21, 0, 0, 'Asia/Jakarta')
        );
        $summary = ['date' => $built['date'], 'day' => $built['day'], 'today_classes' => $built['classes']];

        // Built at 21:00 the evening class was in session and leads the list.
        $this->assertSame(['IF202', 'IF101'], array_column($summary['today_classes'], 'course_code'));
        $this->assertTrue($summary['today_classes'][0]['active_now']);

        // Read again the next morning: that snapshot is yesterday's, nothing is in session.
        $nextMorning = $builder->refreshActiveState($summary, CarbonImmutable::create(2026, 4, 14, 8, 30, 0, 'Asia/Jakarta'));

        $this->assertSame(['IF101', 'IF202'], array_column($nextMorning['today_classes'], 'course_code'));
        $this->assertSame([false, false], array_column($nextMorning['today_classes'], 'active_now'));

        // Read the same day at 08:30: the morning class is the one in session.
        $sameMorning = $builder->refreshActiveState($summary, CarbonImmutable::create(2026, 4, 13, 8, 30, 0, 'Asia/Jakarta'));

        $this->assertSame(['IF101', 'IF202'], array_column($sameMorning['today_classes'], 'course_code'));
        $this->assertSame([true, false], array_column($sameMorning['today_classes'], 'active_now'));
        $this->assertSame('2026-04-13', $sameMorning['date']);
    }

    public function test_refreshing_leaves_a_summary_without_classes_untouched(): void
    {
        $builder = new DashboardTodayClassesBuilder;
        $reference = CarbonImmutable::create(2026, 4, 13, 9, 0, 0, 'Asia/Jakarta');

        $this->assertSame([], $builder->refreshActiveState([], $reference));
        $this->assertSame(
            ['date' => '2026-04-13', 'today_classes' => []],
            $builder->refreshActiveState(['date' => '2026-04-13', 'today_classes' => []], $reference)
        );
    }

    public function test_attendance_shortcuts_of_the_same_day_are_joined_onto_the_classes(): void
    {
        $summary = $this->saturdaySummary();

        $joined = (new DashboardTodayClassesBuilder)->attachAttendance($summary, [
            'date' => '2026-04-11',
            'classes' => [$this->shortcut(reliable: true)],
        ], 'synced', '2026-04-11T09:55:00+07:00');
        $attendance = $joined['today_classes'][0]['attendance'];

        $this->assertSame('ready', $attendance['state']);
        $this->assertTrue($attendance['available']);
        $this->assertTrue($attendance['actionable']);
        $this->assertSame('445', $attendance['meeting_id']);
        $this->assertSame(6, $attendance['meeting_number']);
        $this->assertSame('/meeting/445?courses_id=8810', $attendance['meeting_url']);
        $this->assertSame('/class/2265?dosen_id=5240&course_id=8810', $attendance['class_url']);
        $this->assertSame(['courseid' => '8810', 'modulesid' => '445', 'pertemuan' => 6], $attendance['payload']);
        $this->assertSame(1, $joined['actionable_attendance_count']);
        $this->assertSame(['status' => 'synced', 'synced_at' => '2026-04-11T09:55:00+07:00'], $joined['attendance_sync']);
    }

    public function test_it_does_not_expose_unreliable_attendance_targets_as_actionable(): void
    {
        $joined = (new DashboardTodayClassesBuilder)->attachAttendance($this->saturdaySummary(), [
            'date' => '2026-04-11',
            'classes' => [$this->shortcut(reliable: false)],
        ], 'synced');
        $attendance = $joined['today_classes'][0]['attendance'];

        $this->assertSame(0, $joined['actionable_attendance_count']);
        $this->assertTrue($attendance['available']);
        $this->assertFalse($attendance['target_reliable']);
        $this->assertFalse($attendance['actionable']);
        $this->assertNull($attendance['payload']);
        $this->assertNull($attendance['context']);
    }

    public function test_classes_say_their_attendance_is_being_checked_until_todays_shortcuts_are_in(): void
    {
        $builder = new DashboardTodayClassesBuilder;
        $yesterday = ['date' => '2026-04-10', 'classes' => [$this->shortcut(reliable: true)]];

        foreach (['pending' => null, 'syncing' => $yesterday] as $status => $shortcuts) {
            $joined = $builder->attachAttendance($this->saturdaySummary(), $shortcuts, $status);
            $attendance = $joined['today_classes'][0]['attendance'];

            $this->assertSame('checking', $attendance['state'], $status);
            $this->assertFalse($attendance['available'], $status);
            $this->assertFalse($attendance['actionable'], $status);
            $this->assertNull($attendance['meeting_id'], $status);
            $this->assertSame('/class/2265?dosen_id=5240&course_id=8810', $attendance['class_url'], $status);
            $this->assertSame(0, $joined['actionable_attendance_count'], $status);
        }

        $failed = $builder->attachAttendance($this->saturdaySummary(), null, 'failed');

        $this->assertSame('unavailable', $failed['today_classes'][0]['attendance']['state']);
        $this->assertSame('failed', $failed['attendance_sync']['status']);
    }

    public function test_a_schedule_stored_with_its_attendance_keeps_it_until_shortcuts_are_in(): void
    {
        $summary = $this->saturdaySummary();
        $summary['today_classes'][0]['attendance'] = ['available' => true, 'actionable' => true, 'meeting_id' => '445'];

        $joined = (new DashboardTodayClassesBuilder)->attachAttendance($summary, null, 'pending');

        $this->assertSame(['available' => true, 'actionable' => true, 'meeting_id' => '445', 'state' => 'ready'], $joined['today_classes'][0]['attendance']);
        $this->assertSame(1, $joined['actionable_attendance_count']);
    }

    /**
     * @return array<string, mixed>
     */
    private function saturdaySummary(): array
    {
        $built = (new DashboardTodayClassesBuilder)->build(
            [[
                ...$this->sipandaiClass('Sabtu', '09:30:00', '11:00:00', 'IF101', 'Algoritma'),
                'join_link' => ['params' => ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810']],
            ]],
            [],
            CarbonImmutable::create(2026, 4, 11, 10, 0, 0, 'Asia/Jakarta')
        );

        return [
            'date' => $built['date'],
            'day' => $built['day'],
            'today_classes_count' => count($built['classes']),
            'actionable_attendance_count' => 0,
            'today_classes' => $built['classes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shortcut(bool $reliable): array
    {
        return [
            'class_id' => '2265',
            'meeting_id' => '445',
            'meeting_number' => 6,
            'course_code' => 'IF101',
            'today_material' => ['title' => 'Pertemuan 6', 'meeting_number' => 6],
            'attendance_target_reliable' => $reliable,
            'schedule' => ['day' => 'Sabtu', 'start_time' => '09:30:00', 'time' => '09:30 - 11:00'],
            'attendance_status' => ['already_marked' => false, 'status' => null, 'label' => null, 'time' => null],
            'attendance_payload' => ['courseid' => '8810', 'modulesid' => '445', 'pertemuan' => 6],
            'assignment_payload' => ['courses_id' => '8810'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sipandaiClass(string $day, string $start, string $end, string $code, string $name): array
    {
        return [
            'schedule' => [
                'day' => $day,
                'start_time' => $start,
                'end_time' => $end,
                'time_range' => "{$start}-{$end}",
                'formatted_time' => substr($start, 0, 5).' - '.substr($end, 0, 5),
            ],
            'course_info' => ['code' => $code, 'name' => $name],
            'lecturer_info' => ['name' => 'Dosen Pengampu'],
        ];
    }
}
