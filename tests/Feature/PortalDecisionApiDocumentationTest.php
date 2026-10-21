<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PortalDecisionApiDocumentationTest extends TestCase
{
    public function test_decision_endpoints_are_documented_with_typed_responses(): void
    {
        $exportPath = tempnam(sys_get_temp_dir(), 'scramble-decision-docs-');

        if ($exportPath === false) {
            $this->fail('Failed to allocate a temporary file for OpenAPI export.');
        }

        try {
            Artisan::call('scramble:export', ['--path' => $exportPath]);
            $spec = json_decode((string) file_get_contents($exportPath), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($exportPath);
        }

        $expected = [
            '/action-items' => ['items', 'counts'],
            '/academic/krs' => ['status', 'periods', 'totals', 'insights'],
            '/academic/advising' => ['dpa', 'krs_history', 'latest_krs_id'],
            '/academic/krs/{id}' => ['courses', 'totals', 'history', 'navigation'],
            '/academic/grades' => ['semesters', 'summary', 'transcript', 'krs_validation'],
            '/academic/transcript' => ['summary', 'progress', 'target_sks', 'remaining_sks', 'low_grades', 'simulation'],
            '/announcement' => ['items', 'paging', 'upcoming_deadlines', 'mode'],
            '/announcement/{id}' => ['id', 'title', 'body_html', 'deadlines'],
            '/finance/summary' => ['outstanding_summary', 'outstanding_bills', 'next_stage', 'active_deadline'],
            '/skkm' => ['found', 'stats', 'graduation_status', 'entries', 'summary'],
            '/portal/context' => ['academic_period', 'semester_number', 'guide_links', 'questionnaire'],
            '/dashboard' => ['dashboard_overview', 'portal_context', 'metrics', 'pending_sources'],
            '/thesis' => ['requirements', 'thresholds', 'documents', 'status', 'krs_hint'],
            '/thesis/readiness' => ['checklist_items', 'summary', 'sks_progress', 'courses', 'insights'],
            '/library' => ['links', 'source', 'repository'],
            '/library/theses' => ['mode', 'items'],
        ];

        foreach ($expected as $path => $keys) {
            $operation = $spec['paths'][$path]['get'] ?? null;
            $this->assertIsArray($operation, "{$path} is missing from the OpenAPI export.");
            $this->assertArrayHasKey('401', $operation['responses'], $path);

            $data = $operation['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'] ?? [];

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $data, "{$path} response is missing data.{$key}.");
            }
        }

        $this->assertArrayHasKey('404', $spec['paths']['/academic/krs/{id}']['get']['responses']);
        $this->assertArrayHasKey('422', $spec['paths']['/academic/transcript']['get']['responses']);
        $this->assertArrayHasKey('422', $spec['paths']['/library/theses']['get']['responses']);
    }
}
