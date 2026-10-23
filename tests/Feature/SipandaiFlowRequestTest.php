<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\SipandaiFlowRequest;
use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SipandaiFlowRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_retired_submission_id_parameter_is_neither_documented_nor_validated(): void
    {
        $this->assertArrayNotHasKey('submission_id', (new SipandaiFlowRequest)->rules());

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->once()->with('token-flow')->andReturnTrue();
            })
        );
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractClassDetail')->once()->andReturn(['course_info' => ['name' => 'Data Governance']]);
        }));

        $this->withSession(['campus_session_token' => 'token-flow'])
            ->getJson('/api/lms/flow?id=1688&dosen_id=2744&course_id=9198&pertemuan=5&submission_id=abc&delete_id=-1')
            ->assertOk()
            ->assertJsonPath('data.course_detail.course_info.name', 'Data Governance')
            ->assertJsonMissingPath('data.links.assignment_delete');
    }
}
