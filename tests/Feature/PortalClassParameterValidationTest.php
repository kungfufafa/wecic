<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Class and course ids are portal auto-increment ids: any positive integer
 * is valid, including ids past 9999 once the portal reaches them.
 */
class PortalClassParameterValidationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_class_detail_accepts_ids_past_four_digits(): void
    {
        $this->mockValidCampusSession('token-five-digit');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-five-digit', [
                        'id' => '10021',
                        'dosen_id' => '5240',
                        'course_id' => '10012',
                    ])
                    ->andReturn([
                        'content_type' => 'class_info',
                        'course_info' => ['name' => 'Data Governance'],
                    ]);
            })
        );

        $this->withSession(['campus_session_token' => 'token-five-digit'])
            ->getJson('/api/class/10021?dosen_id=5240&course_id=10012')
            ->assertOk()
            ->assertJsonPath('class_detail.course_info.name', 'Data Governance')
            ->assertJsonPath('meta.parameters.id', '10021')
            ->assertJsonPath('meta.parameters.course_id', '10012');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidClassDetailUrls(): array
    {
        return [
            'class id is not a number' => ['/api/class/abc?dosen_id=5240&course_id=8810', 'id'],
            'class id is zero' => ['/api/class/0?dosen_id=5240&course_id=8810', 'id'],
            'course id is negative' => ['/api/class/2265?dosen_id=5240&course_id=-8810', 'course_id'],
            'course id is a course code' => ['/api/class/2265?dosen_id=5240&course_id=SIE053004', 'course_id'],
            'course id is longer than an id can be' => ['/api/class/2265?dosen_id=5240&course_id=12345678901', 'course_id'],
        ];
    }

    #[DataProvider('invalidClassDetailUrls')]
    public function test_class_detail_rejects_ids_that_are_not_positive_integers(string $url, string $invalidField): void
    {
        $this->mockValidCampusSession('token-invalid');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldNotReceive('extractClassDetail');
            })
        );

        $this->withSession(['campus_session_token' => 'token-invalid'])
            ->getJson($url)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$invalidField]);
    }

    public function test_validate_parameters_accepts_ids_past_four_digits(): void
    {
        $this->getJson('/api/support/validate-parameters?id=10021&dosen_id=5240&course_id=10012')
            ->assertOk()
            ->assertJsonPath('validation_result.valid', true)
            ->assertJsonPath('validation_result.suggested_api_url', '/api/class/10021?dosen_id=5240&course_id=10012');
    }

    public function test_validate_parameters_rejects_a_course_code_as_course_id(): void
    {
        $this->getJson('/api/support/validate-parameters?id=2265&dosen_id=5240&course_id=SIE053004')
            ->assertStatus(422)
            ->assertJsonPath('validation_result.valid', false)
            ->assertJsonStructure(['validation_result' => ['errors' => ['course_id']]]);
    }

    public function test_parameter_help_documents_no_four_digit_range(): void
    {
        $response = $this->getJson('/api/support/parameter-help')->assertOk();

        $this->assertSame('1 or greater', $response->json('parameter_guide.validation_rules.course_id.range'));
        $this->assertStringNotContainsString('9999', (string) $response->getContent());
    }

    private function mockValidCampusSession(string $token, int $times = 1): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token, $times): void {
                $mock->shouldReceive('validateSession')
                    ->times($times)
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}
