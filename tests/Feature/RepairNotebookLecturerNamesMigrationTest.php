<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notebook;
use App\Services\NotebookCourseSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Course notebooks made from the old `/api/lms/class` list stored the
 * lecturer's phone and email in `lecturer_name`; the data migration cuts
 * them off and leaves clean names alone.
 */
class RepairNotebookLecturerNamesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'student_0123456789abcdef';

    private ?Migration $migration = null;

    public function test_the_contact_details_the_old_class_list_appended_are_cut_from_the_lecturer_name(): void
    {
        $fromTheOldList = $this->notebook('8810', 'Dosen Contoh Tiga, M.Pd Kontak Dosen : 081200000001 Email Dosen : dosen.tiga@example.test');
        $protectedEmail = $this->notebook('8861', 'Dosen Contoh Satu, M.Kom. Email Dosen : [email protected]');
        $nonBreakingSpaces = $this->notebook('8833', "Dosen Contoh Dua, MMSI.\u{00A0}Kontak\u{00A0}Dosen\u{00A0}: 08120000003");

        $this->runRepair();

        $this->assertSame('Dosen Contoh Tiga, M.Pd', $fromTheOldList->refresh()->lecturer_name);
        $this->assertSame('Dosen Contoh Satu, M.Kom.', $protectedEmail->refresh()->lecturer_name);
        $this->assertSame('Dosen Contoh Dua, MMSI.', $nonBreakingSpaces->refresh()->lecturer_name);
        $this->assertSame('2026-10-01 08:00:00', Notebook::query()->whereKey($fromTheOldList)->toBase()->value('updated_at'));
    }

    public function test_clean_names_and_notebooks_without_a_lecturer_are_left_alone_and_a_second_run_changes_nothing(): void
    {
        $clean = $this->notebook('8810', 'Dosen A');
        $withoutLecturer = $this->notebook('8861', null);
        $polluted = $this->notebook('8833', 'Dosen Contoh Dua, MMSI. Kontak Dosen : 08120000003');

        $this->runRepair();
        $this->runRepair();

        $this->assertSame('Dosen A', $clean->refresh()->lecturer_name);
        $this->assertNull($withoutLecturer->refresh()->lecturer_name);
        $this->assertSame('Dosen Contoh Dua, MMSI.', $polluted->refresh()->lecturer_name);
    }

    public function test_a_lecturer_name_that_was_only_contact_details_is_cleared_so_the_next_sync_fills_it(): void
    {
        $onlyContact = $this->notebook('8810', 'Kontak Dosen : 081200000001 Email Dosen : dosen.tiga@example.test');

        $this->runRepair();

        $this->assertNull($onlyContact->refresh()->lecturer_name);

        app(NotebookCourseSync::class)->courseNotebook(self::OWNER, '8810', ['lecturer_name' => 'Dosen Contoh Tiga, M.Pd']);

        $this->assertSame('Dosen Contoh Tiga, M.Pd', $onlyContact->refresh()->lecturer_name);
    }

    private function notebook(string $courseKey, ?string $lecturerName): Notebook
    {
        $notebook = Notebook::factory()->forOwner(self::OWNER)->course($courseKey)->create(['lecturer_name' => $lecturerName]);
        Notebook::query()->whereKey($notebook)->toBase()->update(['updated_at' => '2026-10-01 08:00:00']);

        return $notebook;
    }

    private function runRepair(): void
    {
        $this->migration ??= require database_path('migrations/2026_10_22_165404_repair_notebook_lecturer_names.php');
        $this->migration->up();
    }
}
