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

        $this->assertSame('20990000001', $data['personal_data']['nim']);
        $this->assertSame('Mahasiswa Contoh', $data['personal_data']['nama']);
        $this->assertSame('Aktif', $data['personal_data']['status']);
        $this->assertSame('Kota Lahir Contoh', $data['personal_data']['tempat_lahir']);
        $this->assertSame('Jl. Contoh No. 1', $data['contact_data']['alamat_rumah']);
        $this->assertSame('Kota Contoh', $data['contact_data']['kota']);
        $this->assertSame('081200000000', $data['contact_data']['hp']);
        $this->assertSame('mahasiswa@example.test', $data['contact_data']['email']);
    }
}
