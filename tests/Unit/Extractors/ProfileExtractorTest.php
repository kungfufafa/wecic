<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ProfileExtractor;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class ProfileExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_personal_and_contact_information_from_fixture(): void
    {
        /** @var ProfileExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(
            ProfileExtractor::class,
            'tests/Fixtures/portal/profile.html'
        );

        $data = $extractor->extractProfileData('fake-session-token');

        $this->assertSame('20251030030', $data['personal_data']['nim']);
        $this->assertSame('Apriansyah Rizqi Setiawan', $data['personal_data']['nama']);
        $this->assertSame('Aktif', $data['personal_data']['status']);
        $this->assertSame('Karawang', $data['personal_data']['tempat_lahir']);
        $this->assertSame('Jl. Kembang', $data['contact_data']['alamat_rumah']);
        $this->assertSame('Kota Cirebon', $data['contact_data']['kota']);
        $this->assertSame('0895636786435', $data['contact_data']['hp']);
        $this->assertSame('apriansyah@rizqis.com', $data['contact_data']['email']);
    }
}
