<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ClassesExtractorCourseInfoParsingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_credits_when_course_name_contains_hyphen(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        $extractor = Mockery::mock(ClassesExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->andReturn(<<<'HTML'
                <div class="card">
                    <div class="content">
                        <table>
                            <tr>
                                <td>Nama Mata Kuliah</td>
                                <td>:</td>
                                <td><b>IF204 - Machine-Learning Dasar - 2 SKS</b></td>
                            </tr>
                        </table>
                    </div>
                </div>
            HTML);

        $data = $extractor->extractClassDetail('fake-session-token', [
            'id' => '10',
            'dosen_id' => '20',
            'course_id' => '30',
        ]);

        $this->assertSame('IF204', $data['course_info']['code']);
        $this->assertSame('Machine-Learning Dasar', $data['course_info']['name']);
        $this->assertSame('2', $data['course_info']['credits']);
    }
}
