<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalArchiveVersion;
use App\Services\PortalDataCache;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The archive history and evidence pages (routes/web.php, resources/views/archive).
 */
class PortalArchivePagesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-archive-pages';

    private const USERNAME = '20990000007';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('isSessionUsable')->andReturnTrue();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_link_to_one_version_opens_the_class_it_belongs_to(): void
    {
        $classA = ['id' => '91', 'dosen_id' => '7', 'course_id' => '501'];
        $suspect = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->create([
            'dataset' => 'class_detail',
            'params' => $classA,
            'params_hash' => PortalDataCache::paramsHash($classA),
        ]);

        $this->page('/archive/class_detail?version='.$suspect->id)
            ->assertOk()
            ->assertViewHas('paramsHash', PortalDataCache::paramsHash($classA));
    }

    public function test_the_class_in_the_link_wins_and_a_malformed_one_is_ignored(): void
    {
        $classA = ['id' => '91', 'dosen_id' => '7', 'course_id' => '501'];
        $suspect = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->create([
            'dataset' => 'class_detail',
            'params' => $classA,
            'params_hash' => PortalDataCache::paramsHash($classA),
        ]);
        $chosen = str_repeat('a', 32);

        $this->page('/archive/class_detail?params_hash='.$chosen.'&version='.$suspect->id)->assertViewHas('paramsHash', $chosen);
        $this->page('/archive/class_detail?params_hash=%3Cscript%3E')->assertViewHas('paramsHash', null);
        $this->page('/archive/class_detail')->assertViewHas('paramsHash', null);
    }

    public function test_a_version_of_another_dataset_or_student_does_not_pick_the_class(): void
    {
        $skkm = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->create();
        $foreign = PortalArchiveVersion::factory()->forOwner('student_someone_else')->create([
            'dataset' => 'class_detail',
            'params_hash' => str_repeat('b', 32),
        ]);

        $this->page('/archive/class_detail?version='.$skkm->id)->assertOk()->assertViewHas('paramsHash', null);
        $this->page('/archive/class_detail?version='.$foreign->id)->assertOk()->assertViewHas('paramsHash', null);
    }

    public function test_the_evidence_lists_every_entry_and_says_when_its_diff_is_cut(): void
    {
        $entries = array_map(static fn (int $index): array => ['name' => 'Kegiatan '.$index, 'status' => 'valid'], range(1, 1100));
        PortalArchiveVersion::factory()->forOwner($this->ownerHash)->withPayload(['found' => true, 'entries' => $entries])->create();
        $suspect = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->withPayload(['found' => true, 'entries' => [['name' => 'Kegiatan 1', 'status' => 'valid']]])->create();
        $baseline = PortalArchiveVersion::query()->trusted()->sole();

        $this->page('/archive/versions/'.$suspect->id.'/print')
            ->assertOk()
            ->assertViewHas('baseline', fn (?PortalArchiveVersion $shown): bool => $shown?->is($baseline) ?? false)
            ->assertSee('Menampilkan 2000 dari 2198 perubahan.');

        $this->page('/archive/versions/'.$baseline->id.'/print')
            ->assertOk()
            ->assertDontSee('data-diff-truncated', false)
            ->assertSee('Kegiatan 1100');
    }

    public function test_the_evidence_prints_dark_theme_pages_in_light_colours(): void
    {
        $version = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->create();

        // The light print tokens come from app.css, which the layout loads on every page.
        $this->page('/archive/versions/'.$version->id.'/print')
            ->assertOk()
            ->assertViewIs('archive.print')
            ->assertDontSee('--color-ink:', false);

        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("@vite(['resources/css/app.css'", (string) file_get_contents(resource_path('views/layouts/app.blade.php')));
        $this->assertMatchesRegularExpression("/\n@media print \{\s*html\[data-theme='dark'\] \{\s*color-scheme: light;.*?--color-ink: #171717;/s", $css);
    }

    public function test_a_refused_background_sync_choice_shows_the_status_that_applies(): void
    {
        $this->page('/archive')
            ->assertOk()
            ->assertSeeInOrder(['const effectiveStatus = error?.payload?.data;', 'renderBackgroundSync(effectiveStatus);', 'backgroundSyncToggle.checked = !enabled;'], false);
    }

    private function page(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])->get($uri);
    }
}
