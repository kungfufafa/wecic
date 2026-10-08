<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ProfileExtractorEmailTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_cloudflare_obfuscated_email_is_decoded(): void
    {
        $this->assertSame('mahasiswa@cic.ac.id', $this->invoke('decodeCloudflareEmail', ['5a373b323b2933292d3b1a393339743b3974333e']));
        $this->assertNull($this->invoke('decodeCloudflareEmail', ['zz']));
        $this->assertNull($this->invoke('decodeCloudflareEmail', ['5a37']));
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $extractor = new ProfileExtractor(Mockery::mock(PortalSessionManager::class));
        $reflection = new ReflectionMethod($extractor, $method);

        return $reflection->invoke($extractor, ...$arguments);
    }
}
