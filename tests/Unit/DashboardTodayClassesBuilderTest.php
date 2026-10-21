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
}
