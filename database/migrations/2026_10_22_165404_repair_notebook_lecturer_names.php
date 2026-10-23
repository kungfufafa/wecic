<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The old `/api/lms/class` parser read the whole bold lecturer cell as the
 * name, so a course notebook made from that list stored the lecturer's
 * phone number and email after the name ("Dosen A, M.Kom Kontak Dosen :
 * 0812… Email Dosen : …"). A notebook only fills a missing lecturer, so the
 * clean name a later sync brings never replaced it. This cuts the contact
 * part off the way PortalScrapingService::parseLecturerCell() does; a value
 * that was only contact details becomes null so the next sync fills it.
 * Clean names are left alone, so running it again changes nothing.
 */
return new class extends Migration
{
    /**
     * The contact part the old parser appended to the lecturer's name.
     */
    private const CONTACT_SUFFIX = '/\s*(?:Kontak|Email)\s+Dosen\s*:.*$/isu';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('notebooks')) {
            return;
        }

        DB::table('notebooks')
            ->select(['id', 'lecturer_name'])
            ->where('lecturer_name', 'like', '%Dosen%')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $name = (string) $row->lecturer_name;
                    $repaired = trim((string) preg_replace(self::CONTACT_SUFFIX, '', $name));

                    if ($repaired !== $name) {
                        DB::table('notebooks')->where('id', $row->id)->update([
                            'lecturer_name' => $repaired !== '' ? $repaired : null,
                        ]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * The removed phone numbers and emails were never part of the name and
     * are not put back.
     */
    public function down(): void {}
};
