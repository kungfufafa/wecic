<?php

namespace App\Console\Commands;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalAuthService;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Illuminate\Console\Command;

class ScrapeTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:test {--analyze : Analyze URL structure and extract valid data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test scraping from sample URLs to extract valid data structure';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Testing URL Structure Analysis ===');
        $this->newLine();

        // Initialize services
        $sessionManager = app(PortalSessionManager::class);
        $scrapingService = new PortalScrapingService($sessionManager);
        $authService = new PortalAuthService($scrapingService);
        $classesExtractor = new ClassesExtractor($scrapingService);

        // Test URLs from user
        $testUrls = [
            [
                'name' => 'URL 1',
                'url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861',
                'params' => [
                    'id' => '3142',
                    'dosen_id' => '1863',
                    'course_id' => '8861',
                ],
            ],
            [
                'name' => 'URL 2',
                'url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=download&id=1451&dosen_id=2744&course_id=8833',
                'params' => [
                    'id' => '1451',
                    'dosen_id' => '2744',
                    'course_id' => '8833',
                ],
            ],
        ];

        // First, let's try to login
        $this->info('1. Testing Login...');
        try {
            $sessionToken = $authService->authenticate('20251030030', 'Kanjutz123@');

            if ($sessionToken) {
                $this->info('✅ Login successful!');
                $this->line('Session Token: '.substr($sessionToken, 0, 20).'...');
                $this->newLine();

                // Test scraping each URL
                foreach ($testUrls as $index => $testUrl) {
                    $this->info('2. Testing '.$testUrl['name'].':');
                    $this->line('URL: '.$testUrl['url']);
                    $this->line('Parameters: '.json_encode($testUrl['params']));

                    try {
                        // Extract class detail data
                        $result = $classesExtractor->extractClassDetail($sessionToken, $testUrl['params']);

                        $this->info('✅ Scraping successful!');
                        $this->line('Result keys: '.json_encode(array_keys($result)));

                        // Analyze the structure
                        $this->newLine();
                        $this->info('--- DATA STRUCTURE ANALYSIS ---');

                        $this->analyzeData($result);

                        $this->newLine();
                        $this->line(str_repeat('-', 50));

                    } catch (\Exception $e) {
                        $this->error('❌ Scraping failed: '.$e->getMessage());
                        $this->newLine();
                    }
                }

            } else {
                $this->error('❌ Login failed!');
            }

        } catch (\Exception $e) {
            $this->error('❌ Error: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('=== Analysis Complete ===');

        return Command::SUCCESS;
    }

    private function analyzeData(array $result): void
    {
        if (isset($result['course_info'])) {
            $this->info('📚 Course Info:');
            $this->line('  - Name: '.($result['course_info']['name'] ?? 'N/A'));
            $this->line('  - Code: '.($result['course_info']['code'] ?? 'N/A'));
            $this->line('  - Class: '.($result['course_info']['class_name'] ?? 'N/A'));
        }

        if (isset($result['class_info'])) {
            $this->info('📋 Class Info:');
            $this->line('  - Name: '.($result['class_info']['name'] ?? 'N/A'));
            $this->line('  - Type: '.($result['class_info']['type'] ?? 'N/A'));
        }

        if (isset($result['lecturer_info'])) {
            $this->info('👨‍🏫 Lecturer Info:');
            $this->line('  - Name: '.($result['lecturer_info']['name'] ?? 'N/A'));
            $this->line('  - Contact: '.($result['lecturer_info']['contact'] ?? 'N/A'));
            $this->line('  - Email: '.($result['lecturer_info']['email'] ?? 'N/A'));
        }

        if (isset($result['schedule_info'])) {
            $this->info('⏰ Schedule Info:');
            $this->line('  - Date: '.($result['schedule_info']['date'] ?? 'N/A'));
            $this->line('  - Time: '.($result['schedule_info']['start_time'] ?? 'N/A'));
            $this->line('  - Room: '.($result['schedule_info']['room'] ?? 'N/A'));
        }

        if (isset($result['attendance_info'])) {
            $this->info('✅ Attendance Info:');
            $this->line('  - Status: '.($result['attendance_info']['status'] ?? 'N/A'));
            $this->line('  - Time: '.($result['attendance_info']['time'] ?? 'N/A'));
        }

        if (isset($result['rps_info'])) {
            $this->info('📄 RPS Info:');
            $this->line('  - File Link: '.($result['rps_info']['file_link'] ?? 'N/A'));
            $this->line('  - File Text: '.($result['rps_info']['file_text'] ?? 'N/A'));
        }

        if (isset($result['assignment_info'])) {
            $this->info('📝 Assignment Info:');
            $this->line('  - Upload Link: '.($result['assignment_info']['upload_link'] ?? 'N/A'));
            $this->line('  - Upload Text: '.($result['assignment_info']['upload_text'] ?? 'N/A'));
            $this->line('  - Deadline: '.($result['assignment_info']['deadline'] ?? 'N/A'));
        }

        if (isset($result['course_materials']) && is_array($result['course_materials'])) {
            $this->info('📖 Course Materials: '.count($result['course_materials']).' items');
            foreach ($result['course_materials'] as $i => $material) {
                $this->line('  Material '.($i + 1).':');
                $this->line('    - Title: '.($material['title'] ?? 'N/A'));
                $this->line('    - Date: '.($material['date'] ?? 'N/A'));
                $this->line('    - Join Link: '.($material['join_link']['href'] ?? 'N/A'));
                $this->line('    - Join Text: '.($material['join_link']['text'] ?? 'N/A'));
            }
        }

        if (isset($result['conformity_info'])) {
            $this->info('🔍 Conformity Info:');
            $this->line('  - Description: '.($result['conformity_info']['description'] ?? 'N/A'));
        }

        if (isset($result['materials'])) {
            $this->info('📎 Materials:');
            $this->line('  - PDF Links: '.(isset($result['materials']['pdf_links']) ? count($result['materials']['pdf_links']).' items' : 'N/A'));
            $this->line('  - PPT Links: '.(isset($result['materials']['ppt_links']) ? count($result['materials']['ppt_links']).' items' : 'N/A'));
        }
    }
}
