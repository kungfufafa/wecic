<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalArchiveGuard;
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

    public function test_the_profile_archive_guards_read_the_keys_the_extractor_writes(): void
    {
        $trusted = $this->extractProfile('tests/Fixtures/portal/profile.html');
        $changed = $trusted;
        $changed['personal_data']['nim'] = '20990000002';
        $changed['personal_data']['nama'] = 'Mahasiswa Lain';

        $guard = app(PortalArchiveGuard::class);

        $this->assertSame([], $guard->inspect('profile', $trusted, $trusted));
        $this->assertSame([
            ['rule' => 'field', 'label' => 'NIM', 'message' => 'NIM berubah dari 20990000001 menjadi 20990000002.'],
            ['rule' => 'field', 'label' => 'Nama', 'message' => 'Nama berubah dari Mahasiswa Contoh menjadi Mahasiswa Lain.'],
        ], $guard->inspect('profile', $trusted, $changed));
    }

    public function test_a_profile_without_identity_rows_is_not_trusted_over_a_readable_one(): void
    {
        $trusted = $this->extractProfile('tests/Fixtures/portal/profile.html');
        $empty = $trusted;
        $empty['personal_data'] = [];

        $guard = app(PortalArchiveGuard::class);

        $this->assertTrue($guard->isReadable('profile', $trusted));
        $this->assertFalse($guard->isReadable('profile', $empty));
        $this->assertSame('requires', $guard->inspect('profile', $trusted, $empty)[0]['rule'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractProfile(string $fixture): array
    {
        /** @var ProfileExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(ProfileExtractor::class, $fixture);

        $data = $extractor->extractProfileData('fake-session-token');
        unset($data['_sync']);

        return $data;
    }
}
