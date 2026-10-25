<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

/**
 * The anonymous Blade components in resources/views/components: the markup
 * each renders, so pages built from them look as they did when they wrote
 * the same markup out by hand.
 */
class BladeComponentsTest extends TestCase
{
    public function test_page_header_renders_the_title_subtitle_and_what_follows(): void
    {
        $header = $this->render('<x-page-header title="Jadwal & Kelas" subtitle="Semua <kelas>" class="print:hidden"><p data-updated></p></x-page-header>')->filter('header');

        $this->assertSame('mb-4 print:hidden', $header->attr('class'));
        $this->assertSame('mycic-page-heading', $header->filter('h1')->attr('class'));
        $this->assertSame('Jadwal & Kelas', $header->filter('h1')->text());
        $this->assertSame('Semua <kelas>', $header->filter('p.mycic-page-subheading')->text());
        $this->assertCount(1, $header->filter('p.mycic-page-subheading + p[data-updated]'));
    }

    public function test_page_header_takes_a_subtitle_slot_with_its_own_attributes_or_none(): void
    {
        $withSlot = $this->render('<x-page-header title="KRS"><x-slot:subtitle id="krs-period-label">Status KRS</x-slot:subtitle></x-page-header>');

        $this->assertSame('mycic-page-subheading', $withSlot->filter('#krs-period-label')->attr('class'));
        $this->assertSame('Status KRS', $withSlot->filter('#krs-period-label')->text());
        $this->assertCount(0, $this->render('<x-page-header title="Ujian" />')->filter('p'));
    }

    public function test_academic_menu_links_the_three_academic_pages_and_marks_the_current_one(): void
    {
        $menu = $this->render('<x-academic-menu active="grades" class="print:hidden" />')->filter('nav');

        $this->assertSame('mb-4 print:hidden', $menu->attr('class'));
        $this->assertSame('Menu akademik', $menu->attr('aria-label'));
        $this->assertSame(['KRS & Perwalian', 'Nilai', 'Transkrip'], $menu->filter('ul > li.shrink-0 > a')->each(fn (Crawler $link): string => $link->text()));
        $this->assertSame([route('krs.index'), route('grades.index'), route('transcript.index')], $menu->filter('a')->each(fn (Crawler $link): ?string => $link->attr('href')));
        $this->assertSame(['Nilai'], $menu->filter('a[aria-current="page"]')->each(fn (Crawler $link): string => $link->text()));
        $this->assertStringContainsString('bg-panel font-bold text-brand shadow-xs', (string) $menu->filter('a[aria-current="page"]')->attr('class'));
        $this->assertStringContainsString('text-ink-muted hover:text-ink', (string) $menu->filter('a')->first()->attr('class'));
    }

    public function test_view_tab_buttons_carry_the_classes_the_class_page_switches_between(): void
    {
        $tabs = $this->render('<x-view-tabs as="div" role="tablist"><x-view-tab :active="true" id="a">A</x-view-tab><x-view-tab id="b">B</x-view-tab></x-view-tabs>');
        $switchView = (string) file_get_contents(resource_path('views/classes/index.blade.php'));

        $this->assertSame('tablist', $tabs->filter('div')->attr('role'));
        $this->assertStringStartsWith('inline-flex max-w-full items-center gap-1 overflow-x-auto', (string) $tabs->filter('div')->attr('class'));

        foreach (['a' => 'true', 'b' => 'false'] as $id => $selected) {
            $tab = $tabs->filter("#{$id}");

            $this->assertSame('button', $tab->attr('type'));
            $this->assertSame('tab', $tab->attr('role'));
            $this->assertSame($selected, $tab->attr('aria-selected'));
            $this->assertStringContainsString("tab.className = '{$tab->attr('class')}';", $switchView, "switchView() writes the classes tab {$id} starts with.");
        }
    }

    public function test_section_is_labelled_by_its_heading_and_puts_an_aside_beside_it(): void
    {
        $plain = $this->render('<x-section title="Tugas" title-id="tugas" class="min-w-0">Isi</x-section>')->filter('section');

        $this->assertSame('tugas', $plain->attr('aria-labelledby'));
        $this->assertSame('mycic-section-title mb-3', $plain->filter('h2#tugas')->attr('class'));

        $withAside = $this->render('<x-section title="Hari ini" title-id="today" heading-class="mb-0"><x-slot:aside class="mb-3 flex"><span id="date"></span></x-slot:aside>'."\n".'Isi</x-section>');

        $this->assertSame('mb-3 flex', $withAside->filter('section > div')->attr('class'));
        $this->assertSame('mycic-section-title mb-0', $withAside->filter('section > div > h2#today')->attr('class'));
        $this->assertCount(1, $withAside->filter('section > div > h2 + span#date'));
    }

    public function test_card_renders_its_shell_title_header_and_body(): void
    {
        $card = $this->render('<x-card as="section" title="Kontak" title-id="contact-title" class="overflow-hidden"><x-card.body id="contact" class="flex">Isi</x-card.body></x-card>')->filter('section');

        $this->assertSame('mycic-card overflow-hidden', $card->attr('class'));
        $this->assertSame('contact-title', $card->filter('.mycic-card-header > h2.mycic-card-title')->attr('id'));
        $this->assertSame('mycic-card-body flex', $card->filter('#contact')->attr('class'));

        $bare = $this->render('<x-card as="a" href="/skkm"><x-card.header class="flex">Judul</x-card.header></x-card>');

        $this->assertSame('/skkm', $bare->filter('a.mycic-card')->attr('href'));
        $this->assertSame('mycic-card-header flex', $bare->filter('a > div')->attr('class'));
        $this->assertCount(0, $bare->filter('h2'));
    }

    public function test_metric_fills_its_value_and_note_slots_with_their_attributes(): void
    {
        $metric = $this->render('<x-metric label="Hari aktif" class="min-w-0"><x-slot:value id="days" class="text-lg">5</x-slot:value><x-slot:note id="hours"></x-slot:note></x-metric>')->filter('div.mycic-metric');

        $this->assertSame('mycic-metric min-w-0', $metric->attr('class'));
        $this->assertSame('Hari aktif', $metric->filter('.mycic-metric-label')->text());
        $this->assertSame('mycic-metric-value text-lg', $metric->filter('#days')->attr('class'));
        $this->assertSame('5', $metric->filter('#days')->text());
        $this->assertSame('mycic-metric-note', $metric->filter('#hours')->attr('class'));
        $this->assertCount(0, $this->render('<x-metric label="IPK"><x-slot:value>3.5</x-slot:value></x-metric>')->filter('.mycic-metric-note'));
    }

    public function test_empty_state_matches_the_one_scripts_render(): void
    {
        $empty = $this->render('<x-empty-state class="py-8" title="Memuat..." title-class="text-ink-subtle" subtitle="Sebentar" />')->filter('div');

        $this->assertSame('mycic-empty py-8', $empty->attr('class'));
        $this->assertSame('mycic-empty-title text-ink-subtle', $empty->filter('p')->first()->attr('class'));
        $this->assertSame('Sebentar', $empty->filter('p.mycic-empty-subtitle')->text());
        $this->assertCount(1, $this->render('<x-empty-state title="Kosong" />')->filter('p'));
    }

    public function test_button_is_a_link_with_href_and_otherwise_a_plain_button(): void
    {
        $link = $this->render('<x-button href="/exam" variant="secondary" size="sm" class="relative">Ujian</x-button>')->filter('a');

        $this->assertSame('mycic-btn-secondary-sm relative', $link->attr('class'));
        $this->assertSame('/exam', $link->attr('href'));

        $button = $this->render('<x-button data-logout="">Masuk ulang</x-button>')->filter('button');

        $this->assertSame('button', $button->attr('type'));
        $this->assertSame('mycic-btn-primary', $button->attr('class'));
        $this->assertSame('', $button->attr('data-logout'), 'A data attribute passed empty stays empty.');
        $this->assertSame('submit', $this->render('<x-button type="submit">Kirim</x-button>')->filter('button')->attr('type'));
    }

    public function test_list_row_spaces_a_divided_list(): void
    {
        $this->assertSame(
            'flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0 border-b',
            $this->render('<x-list-row class="border-b">Isi</x-list-row>')->filter('div')->attr('class')
        );
    }

    public function test_navigation_items_mark_the_current_page_and_hold_their_badge(): void
    {
        $icon = '<svg class="h-5 w-5"></svg>';
        $bottom = $this->render('<x-nav.bottom-item href="/class" label="Kuliah" :icon="$icon" badge="kuliah" :active="true" />', ['icon' => $icon])->filter('a');

        $this->assertSame('mycic-bottom-nav-item mycic-bottom-nav-item-active', $bottom->attr('class'));
        $this->assertSame('page', $bottom->attr('aria-current'));
        $this->assertSame('kuliah', $bottom->attr('data-nav-slot'));
        $this->assertCount(1, $bottom->filter('.mycic-nav-icon > svg + span.mycic-nav-badge.hidden[data-nav-badge="kuliah"]'));

        $top = $this->render('<x-nav.top-link href="/krs" label="Akademik" :icon="$icon" badge="akademik" />', ['icon' => $icon])->filter('a');

        $this->assertSame('mycic-top-nav-link', trim((string) $top->attr('class')));
        $this->assertNull($top->attr('aria-current'));
        $this->assertSame('Akademik', $top->attr('title'));
        $this->assertSame('Akademik', $top->filter('span.sr-only')->text());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $template, array $data = []): Crawler
    {
        return new Crawler('<!DOCTYPE html><html><body>'.$this->blade($template, $data).'</body></html>');
    }
}
