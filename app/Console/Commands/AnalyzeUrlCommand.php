<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AnalyzeUrlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analyze:urls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze URL structure and parameter patterns for CIC scraping';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== URL Parameter Structure Analysis ===');
        $this->newLine();

        // Test URLs from user
        $testUrls = [
            [
                'name' => 'URL 1 - Class 3142',
                'url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861',
                'params' => [
                    'id' => '3142',
                    'dosen_id' => '1863',
                    'course_id' => '8861',
                ],
            ],
            [
                'name' => 'URL 2 - Class 1451',
                'url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=download&id=1451&dosen_id=2744&course_id=8833',
                'params' => [
                    'id' => '1451',
                    'dosen_id' => '2744',
                    'course_id' => '8833',
                ],
            ],
        ];

        $this->info('📋 Parameter Structure Analysis:');
        $this->newLine();

        foreach ($testUrls as $index => $testUrl) {
            $this->info(($index + 1).'. '.$testUrl['name']);
            $this->line('   URL: '.$testUrl['url']);
            $this->line('   Parameters: '.json_encode($testUrl['params'], JSON_PRETTY_PRINT));
            $this->newLine();
        }

        $this->info('🔗 URL Pattern Analysis:');
        $this->line('   Base URL: https://my.cic.ac.id/portal/sipandai/');
        $this->line('   Module: mod=sipandai');
        $this->line('   Action: act=download');
        $this->line('   Required Parameters:');
        $this->line('     - id: Unique class identifier (integer)');
        $this->line('     - dosen_id: Lecturer identifier (integer)');
        $this->line('     - course_id: Course identifier (integer)');
        $this->newLine();

        $this->info('📊 Expected Data Structure:');
        $this->analyzeExpectedStructure();
        $this->newLine();

        $this->info('🎯 Usage Recommendations:');
        $this->recommendUsage();
        $this->newLine();

        $this->info('🔧 Parameter Validation Rules:');
        $this->validationRules();
        $this->newLine();

        $this->info('✅ Analysis Complete');

        return Command::SUCCESS;
    }

    private function analyzeExpectedStructure(): void
    {
        $this->line('   Based on current ClassesExtractor implementation, the expected data structure is:');
        $this->newLine();

        $structure = [
            'course_info' => [
                'name' => 'string - Course name',
                'code' => 'string - Course code',
                'class_name' => 'string - Class name/section',
                'credits' => 'string - Number of credits',
            ],
            'lecturer_info' => [
                'name' => 'string - Lecturer name',
                'contact' => 'string - Contact number',
                'email' => 'string - Email address',
            ],
            'schedule_info' => [
                'date' => 'string - Meeting date',
                'start_time' => 'string - Start time',
                'end_time' => 'string - End time',
                'room' => 'string - Classroom/room',
            ],
            'attendance_info' => [
                'status' => 'string - Attendance status',
                'time' => 'string - Check-in time',
            ],
            'rps_info' => [
                'file_link' => 'string - RPS file download link',
                'file_text' => 'string - RPS file button text',
            ],
            'assignment_info' => [
                'upload_link' => 'string - Assignment upload link',
                'upload_text' => 'string - Upload button text',
                'deadline' => 'string - Assignment deadline',
            ],
            'course_materials' => [
                '*' => [
                    'title' => 'string - Material title',
                    'date' => 'string - Material date',
                    'time' => 'string - Material time',
                    'description' => 'string - Material description',
                    'join_link' => [
                        'href' => 'string - Join meeting link',
                        'text' => 'string - Join button text',
                    ],
                ],
            ],
            'conformity_info' => [
                'description' => 'string - Conformity feedback',
            ],
            'materials' => [
                'pdf_links' => 'array - PDF material links',
                'ppt_links' => 'array - PowerPoint material links',
            ],
        ];

        foreach ($structure as $section => $fields) {
            $this->info('   '.$section.':');
            foreach ($fields as $field => $description) {
                if (is_array($description)) {
                    $this->line('     '.$field.': '.json_encode($description, JSON_PRETTY_PRINT));
                } else {
                    $this->line('     '.$field.': '.$description);
                }
            }
            $this->newLine();
        }
    }

    private function recommendUsage(): void
    {
        $this->line('   1. Parameter Usage:');
        $this->line('      - Use id for class identification (primary key)');
        $this->line('      - Use dosen_id for lecturer-specific filtering');
        $this->line('      - Use course_id for course-specific data');
        $this->newLine();

        $this->line('   2. API Endpoints:');
        $this->line('      - GET /api/class/{id}?dosen_id={dosen_id}&course_id={course_id}');
        $this->line('      - GET /api/meeting/{meeting_id}?courses_id={courses_id}');
        $this->newLine();

        $this->line('   3. API-only Usage:');
        $this->line('      - Call /api/class/{id} directly with upstream parameters');
        $this->line('      - Call /api/meeting/{meeting_id} directly with courses_id');
        $this->line('      - Where meeting_id is extracted from course_materials[].join_link.href');
        $this->newLine();

        $this->line('   4. Error Handling:');
        $this->line('      - Always handle missing parameters gracefully');
        $this->line('      - Provide fallback values for missing data');
        $this->line('      - Validate parameter format before processing');
    }

    private function validationRules(): void
    {
        $this->line('   1. ID Parameter:');
        $this->line('      - Type: Integer');
        $this->line('      - Range: 1000-9999 (observed from sample URLs)');
        $this->line('      - Required: Yes');
        $this->line('      - Pattern: /^\d{4}$/');
        $this->newLine();

        $this->line('   2. Dosen ID Parameter:');
        $this->line('      - Type: Integer');
        $this->line('      - Range: 1000-9999 (observed from sample URLs)');
        $this->line('      - Required: Yes');
        $this->line('      - Pattern: /^\d{4}$/');
        $this->newLine();

        $this->line('   3. Course ID Parameter:');
        $this->line('      - Type: Integer');
        $this->line('      - Range: 8000-9999 (observed from sample URLs)');
        $this->line('      - Required: Yes');
        $this->line('      - Pattern: /^\d{4}$/');
        $this->newLine();

        $this->line('   4. Sample Valid URLs:');
        $this->line('      - /portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861');
        $this->line('      - /portal/sipandai/?mod=sipandai&act=download&id=1451&dosen_id=2744&course_id=8833');
        $this->newLine();

        $this->line('   5. Parameter Combinations:');
        $this->line('      - Each unique combination represents a specific class');
        $this->line('      - Same id with different dosen_id = different class sections');
        $this->line('      - Same course_id with different id = different class meetings');
    }
}
