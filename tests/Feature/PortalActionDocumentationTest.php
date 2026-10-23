<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PortalActionDocumentationTest extends TestCase
{
    public function test_upload_assignment_docs_expose_attachment_before_form_values(): void
    {
        $exportPath = tempnam(sys_get_temp_dir(), 'scramble-assignment-docs-');

        if ($exportPath === false) {
            $this->fail('Failed to allocate a temporary file for OpenAPI export.');
        }

        try {
            Artisan::call('scramble:export', [
                '--path' => $exportPath,
            ]);

            $spec = json_decode((string) file_get_contents($exportPath), true, flags: JSON_THROW_ON_ERROR);
            $properties = array_keys($spec['components']['schemas']['SipandaiAssignmentUploadRequest']['properties'] ?? []);

            $this->assertSame([
                'courses_id',
                'meeting_id',
                'id',
                'pertemuan',
                'attachment',
                'form_values',
            ], $properties);
        } finally {
            @unlink($exportPath);
        }
    }

    public function test_pending_assignments_endpoint_is_present_in_scramble_export(): void
    {
        $exportPath = tempnam(sys_get_temp_dir(), 'scramble-assignment-docs-');

        if ($exportPath === false) {
            $this->fail('Failed to allocate a temporary file for OpenAPI export.');
        }

        try {
            Artisan::call('scramble:export', [
                '--path' => $exportPath,
            ]);

            $spec = json_decode((string) file_get_contents($exportPath), true, flags: JSON_THROW_ON_ERROR);

            $this->assertArrayHasKey('/assignment/pending', $spec['paths'] ?? []);
            $this->assertSame(
                'portalAction.pendingAssignments',
                $spec['paths']['/assignment/pending']['get']['operationId'] ?? null
            );
        } finally {
            @unlink($exportPath);
        }
    }

    public function test_boolean_responses_document_the_shared_status_and_meta_keys(): void
    {
        $exportPath = tempnam(sys_get_temp_dir(), 'scramble-envelope-docs-');

        if ($exportPath === false) {
            $this->fail('Failed to allocate a temporary file for OpenAPI export.');
        }

        try {
            Artisan::call('scramble:export', [
                '--path' => $exportPath,
            ]);

            $spec = json_decode((string) file_get_contents($exportPath), true, flags: JSON_THROW_ON_ERROR);
            $responses = [
                $spec['paths']['/operations/{operation}']['get']['responses']['200'] ?? null,
                $spec['paths']['/operations/{operation}']['get']['responses']['404'] ?? null,
                $spec['paths']['/exam']['get']['responses']['200'] ?? null,
                $spec['paths']['/assignment']['post']['responses']['409'] ?? null,
            ];

            foreach ($responses as $response) {
                $schema = $response['content']['application/json']['schema'] ?? [];

                $this->assertSame(['success', 'status', 'message', 'data', 'meta'], array_keys($schema['properties'] ?? []));
                $this->assertSame(['timestamp'], $schema['properties']['meta']['required'] ?? null);
            }
        } finally {
            @unlink($exportPath);
        }
    }
}
