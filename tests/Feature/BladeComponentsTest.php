<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * The anonymous Blade components in resources/views/components: the markup
 * each renders, and that their classes are the strings scripts draw the
 * same thing with (resources/js/app/ui.js, `app.ui`).
 */
class BladeComponentsTest extends TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $ui = null;

    public function test_page_header_renders_the_title_subtitle_and_what_follows(): void
    {
        $header = $this->render('<x-page-header title="Jadwal & Kelas" subtitle="Semua <kelas>" class="print:hidden"><p data-updated></p></x-page-header>')->filter('header');

        $this->assertSame($this->ui('pageHeader').' print:hidden', $header->attr('class'));
        $this->assertSame($this->ui('pageTitle'), $header->filter('h1')->attr('class'));
        $this->assertSame('Jadwal & Kelas', $header->filter('h1')->text());
        $this->assertSame('Semua <kelas>', $header->filter('h1 + p')->text());
        $this->assertSame($this->ui('pageSubtitle'), $header->filter('h1 + p')->attr('class'));
        $this->assertCount(1, $header->filter('h1 + p + p[data-updated]'));
    }

    public function test_page_header_takes_a_subtitle_slot_with_its_own_attributes_or_none(): void
    {
        $withSlot = $this->render('<x-page-header title="KRS"><x-slot:subtitle id="krs-period-label">Status KRS</x-slot:subtitle></x-page-header>');

        $this->assertSame($this->ui('pageSubtitle'), $withSlot->filter('#krs-period-label')->attr('class'));
        $this->assertSame('Status KRS', $withSlot->filter('#krs-period-label')->text());
        $this->assertCount(0, $this->render('<x-page-header title="Ujian" />')->filter('p'));
    }

    public function test_page_header_takes_a_back_link_an_eyebrow_and_actions(): void
    {
        $header = $this->render('<x-page-header title="Riwayat" eyebrow="Kartu Rencana Studi" back="/krs" back-label="KRS"><x-slot:actions><x-button variant="secondary" size="sm">Cetak</x-button></x-slot:actions></x-page-header>')->filter('header');

        $this->assertSame('/krs', $header->filter('a')->first()->attr('href'));
        $this->assertSame($this->ui('backLink').' mb-2', $header->filter('a')->first()->attr('class'));
        $this->assertSame('KRS', trim($header->filter('a')->first()->text()));
        $this->assertSame('Kartu Rencana Studi', $header->filter('p + h1')->previousAll()->text());
        $this->assertSame($this->ui('pageEyebrow'), $header->filter('h1')->previousAll()->attr('class'));
        $this->assertSame('Cetak', trim($header->filter('button')->text()));
        $this->assertCount(1, $header->filter('h1'));
    }

    public function test_academic_menu_links_the_three_academic_pages_and_marks_the_current_one(): void
    {
        $menu = $this->render('<x-academic-menu active="grades" class="print:hidden" />')->filter('nav');

        $this->assertSame('mb-4 print:hidden', $menu->attr('class'));
        $this->assertSame('Menu akademik', $menu->attr('aria-label'));
        $this->assertSame(['KRS & Perwalian', 'Nilai', 'Transkrip'], $menu->filter('ul > li.shrink-0 > a')->each(fn (Crawler $link): string => $link->text()));
        $this->assertSame([route('krs.index'), route('grades.index'), route('transcript.index')], $menu->filter('a')->each(fn (Crawler $link): ?string => $link->attr('href')));
        $this->assertSame(['Nilai'], $menu->filter('a[aria-current="page"]')->each(fn (Crawler $link): string => $link->text()));
        $this->assertSame($this->ui('tabs'), $menu->filter('ul')->attr('class'));
        // aria-current draws the active tab, so every link carries the same classes.
        $this->assertSame([$this->ui('tab')], array_values(array_unique($menu->filter('a')->extract(['class']))));
    }

    public function test_view_tab_buttons_switch_by_aria_selected_alone(): void
    {
        $tabs = $this->render('<x-view-tabs as="div" role="tablist"><x-view-tab :active="true" id="a">A</x-view-tab><x-view-tab id="b">B</x-view-tab></x-view-tabs>');
        $switchView = (string) file_get_contents(resource_path('views/classes/index/scripts/view-modes.blade.php'));

        $this->assertSame('tablist', $tabs->filter('div')->attr('role'));
        $this->assertSame($this->ui('tabs'), $tabs->filter('div')->attr('class'));

        foreach (['a' => 'true', 'b' => 'false'] as $id => $selected) {
            $tab = $tabs->filter("#{$id}");

            $this->assertSame($this->ui('tab'), $tab->attr('class'));
            $this->assertSame('button', $tab->attr('type'));
            $this->assertSame('tab', $tab->attr('role'));
            $this->assertSame($selected, $tab->attr('aria-selected'));
            $this->assertSame('', $tab->attr('data-view-tab'));
        }

        // switchView() only flips aria-selected; a class it wrote would fight the component's.
        $this->assertStringContainsString("tab.setAttribute('aria-selected'", $switchView);
        $this->assertDoesNotMatchRegularExpression('/tab\.className\s*=/', $switchView);
    }

    public function test_view_tabs_draw_underlined_tabs_on_request(): void
    {
        $tabs = $this->render('<x-view-tabs underline><x-view-tab href="/krs" :active="true">KRS</x-view-tab></x-view-tabs>');

        $this->assertSame($this->ui('underlineTabs'), $tabs->filter('ul')->attr('class'));
        $this->assertSame($this->ui('underlineTab'), $tabs->filter('a')->attr('class'));
        $this->assertSame('page', $tabs->filter('a')->attr('aria-current'));

        // The active underline is ink, never a colour.
        $this->assertStringContainsString('aria-[current=page]:border-ink', $this->ui('underlineTab'));
        $this->assertStringContainsString('aria-selected:border-ink', $this->ui('underlineTab'));
        $this->assertStringContainsString('aria-pressed:border-ink', $this->ui('underlineTab'));
        $this->assertStringNotContainsString('border-brand', $this->ui('underlineTab'));

        // The 2px underline and the focus ring stay inside the scroll box: no -mb-px
        // over a bottom border that overflow-x-auto would clip.
        $this->assertStringNotContainsString('-mb-px', $this->ui('underlineTab'));
        $this->assertStringContainsString('focus-visible:ring-inset', $this->ui('underlineTab'));
        $this->assertStringNotContainsString('border-b', $this->ui('underlineTabs'));
        $this->assertStringContainsString('shadow-[inset_0_-1px_0_var(--color-border)]', $this->ui('underlineTabs'));

        // A legacy accent changes nothing, on a route or not.
        $accented = $this->render('<x-view-tabs as="div" underline accent="teal"><x-view-tab :active="true">Cari</x-view-tab></x-view-tabs>');
        $this->assertSame($this->ui('underlineTab'), $accented->filter('button')->attr('class'));
        $this->assertNull($accented->filter('div')->attr('accent'));

        $this->onRoute('/notebook');
        $routed = $this->render('<x-view-tabs as="div" underline><x-view-tab>Chat</x-view-tab></x-view-tabs>');
        $this->assertSame($this->ui('underlineTab'), $routed->filter('button')->attr('class'));

        $this->assertSame($this->ui('tab'), $this->render('<x-view-tabs as="div" accent="teal"><x-view-tab>Cari</x-view-tab></x-view-tabs>')->filter('button')->attr('class'));
    }

    public function test_hand_written_underlined_mode_switch_matches_the_tab_look(): void
    {
        $theses = (string) file_get_contents(resource_path('views/library/theses.blade.php'));

        // The repository's mode switch writes the underlined tab out in full: the same string, no accent.
        $this->assertSame(2, substr_count($theses, 'class="'.$this->ui('underlineTab').'"'));
        $this->assertStringNotContainsString('-mb-px', $theses);
        $this->assertDoesNotMatchRegularExpression('/border-teal-|text-teal-/', $theses);
    }

    public function test_section_is_labelled_by_its_heading_and_puts_an_aside_beside_it(): void
    {
        $plain = $this->render('<x-section title="Tugas" title-id="tugas" class="min-w-0">Isi</x-section>')->filter('section');

        $this->assertSame('tugas', $plain->attr('aria-labelledby'));
        $this->assertSame($this->ui('sectionTitle').' mb-3', $plain->filter('h2#tugas')->attr('class'));

        $withAside = $this->render('<x-section title="Hari ini" title-id="today" heading-class="mb-0"><x-slot:aside class="mb-3 flex"><span id="date"></span></x-slot:aside>'."\n".'Isi</x-section>');

        $this->assertSame('mb-3 flex', $withAside->filter('section > div')->attr('class'));
        $this->assertSame($this->ui('sectionTitle').' mb-0', $withAside->filter('section > div > h2#today')->attr('class'));
        $this->assertCount(1, $withAside->filter('section > div > h2 + span#date'));
    }

    public function test_card_renders_its_shell_title_header_and_body(): void
    {
        $card = $this->render('<x-card as="section" title="Kontak" title-id="contact-title" class="overflow-hidden"><x-card.body id="contact" class="flex">Isi</x-card.body></x-card>')->filter('section');

        $this->assertSame($this->ui('card').' overflow-hidden', $card->attr('class'));
        $this->assertSame('card', $card->attr('data-slot'));
        $this->assertSame('contact-title', $card->filter('[data-slot="card-header"] > h2')->attr('id'));
        $this->assertSame($this->ui('cardHeader'), $card->filter('[data-slot="card-header"]')->attr('class'));
        $this->assertSame($this->ui('cardTitle'), $card->filter('h2')->attr('class'));
        $this->assertSame($this->ui('cardBody').' flex', $card->filter('#contact')->attr('class'));

        $bare = $this->render('<x-card as="a" href="/skkm" interactive><x-card.header class="flex-wrap">Judul</x-card.header></x-card>');

        $this->assertSame('/skkm', $bare->filter('a')->attr('href'));
        $this->assertSame($this->ui('cardInteractive'), $bare->filter('a')->attr('class'));
        $this->assertSame($this->ui('cardHeader').' flex-wrap', $bare->filter('a > div')->attr('class'));
        $this->assertCount(0, $bare->filter('h2'));
    }

    public function test_card_header_takes_a_subtitle_and_actions(): void
    {
        $card = $this->render('<x-card title="Riwayat" subtitle="12 transaksi"><x-slot:actions><a href="/finance">Semua</a></x-slot:actions></x-card>');
        $header = $card->filter('[data-slot="card-header"]');

        $this->assertSame('Riwayat', $header->filter('div.min-w-0 > h2')->text());
        $this->assertSame($this->ui('cardSubtitle'), $header->filter('h2 + p')->attr('class'));
        $this->assertSame('/finance', $header->filter('div.min-w-0 + a')->attr('href'));
    }

    public function test_metric_fills_its_value_and_note_slots_with_their_attributes(): void
    {
        $metric = $this->render('<x-metric label="Hari aktif" class="min-w-0"><x-slot:value id="days" class="text-lg">5</x-slot:value><x-slot:note id="hours"></x-slot:note></x-metric>')->filter('[data-slot="metric"]');

        $this->assertSame($this->ui('metric').' min-w-0', $metric->attr('class'));
        $this->assertSame('Hari aktif', $metric->filter('[data-slot="metric-label"]')->text());
        $this->assertSame($this->ui('metricLabel'), $metric->filter('[data-slot="metric-label"]')->attr('class'));
        $this->assertSame($this->ui('metricValue').' text-lg', $metric->filter('#days')->attr('class'));
        $this->assertSame('5', $metric->filter('#days')->text());
        $this->assertSame($this->ui('metricNote'), $metric->filter('#hours')->attr('class'));
        $this->assertCount(0, $this->render('<x-metric label="IPK"><x-slot:value>3.5</x-slot:value></x-metric>')->filter('[data-slot="metric-note"]'));

        // A cell of the strip: no border, shadow or rounding of its own.
        foreach (['metric', 'metricInteractive'] as $key) {
            $this->assertDoesNotMatchRegularExpression('/\b(border|shadow-xs|rounded-xl)\b/', $this->ui($key), $key);
            $this->assertStringContainsString('bg-panel', $this->ui($key), $key);
        }

        // A legacy icon slot is dropped: the link holds the label, the value and nothing else.
        $linked = $this->render('<x-metric label="Tagihan" href="/finance"><x-slot:icon><svg></svg></x-slot:icon><x-slot:value>-</x-slot:value></x-metric>')->filter('a');

        $this->assertSame('/finance', $linked->attr('href'));
        $this->assertSame($this->ui('metricInteractive'), $linked->attr('class'));
        $this->assertCount(0, $linked->filter('svg'));
        $this->assertCount(0, $linked->filter('[aria-hidden="true"]'));
        $this->assertSame(['metric-label', 'metric-value'], $linked->children()->each(fn (Crawler $part): ?string => $part->attr('data-slot')));
    }

    public function test_empty_state_matches_the_one_scripts_render(): void
    {
        $empty = $this->render('<x-empty-state class="py-8" title="Memuat..." title-class="text-ink-subtle" subtitle="Sebentar" />')->filter('div');

        $this->assertSame($this->ui('empty').' py-8', $empty->attr('class'));
        $this->assertSame($this->ui('emptyTitle').' text-ink-subtle', $empty->filter('p')->first()->attr('class'));
        $this->assertSame('Sebentar', $empty->filter('p')->last()->text());
        $this->assertSame($this->ui('emptySubtitle'), $empty->filter('p')->last()->attr('class'));
        $this->assertCount(1, $this->render('<x-empty-state title="Kosong" />')->filter('p'));
    }

    public function test_button_is_a_link_with_href_and_otherwise_a_plain_button(): void
    {
        $link = $this->render('<x-button href="/exam" variant="secondary" size="sm" class="relative">Ujian</x-button>')->filter('a');

        $this->assertSame($this->ui('button.secondarySm').' relative', $link->attr('class'));
        $this->assertSame('/exam', $link->attr('href'));

        $button = $this->render('<x-button data-logout="">Masuk ulang</x-button>')->filter('button');

        $this->assertSame('button', $button->attr('type'));
        $this->assertSame($this->ui('button.primary'), $button->attr('class'));
        $this->assertSame('', $button->attr('data-logout'), 'A data attribute passed empty stays empty.');
        $this->assertSame('submit', $this->render('<x-button type="submit">Kirim</x-button>')->filter('button')->attr('type'));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function buttonVariants(): array
    {
        return [
            'primary' => ['primary', null, 'button.primary'],
            'secondary' => ['secondary', null, 'button.secondary'],
            'ghost' => ['ghost', null, 'button.ghost'],
            'destructive' => ['destructive', null, 'button.destructive'],
            'icon' => ['icon', null, 'button.icon'],
            'primary sm' => ['primary', 'sm', 'button.primarySm'],
            'secondary sm' => ['secondary', 'sm', 'button.secondarySm'],
            'ghost sm' => ['ghost', 'sm', 'button.ghostSm'],
            'destructive sm' => ['destructive', 'sm', 'button.destructiveSm'],
            'icon sm' => ['icon', 'sm', 'button.iconSm'],
            'on brand (legacy, draws secondary)' => ['on-brand', null, 'button.secondary'],
            'on brand sm (legacy, draws secondary)' => ['on-brand', 'sm', 'button.secondarySm'],
            'unknown' => ['unknown', null, 'button.primary'],
        ];
    }

    #[DataProvider('buttonVariants')]
    public function test_button_classes_are_the_ones_scripts_use(string $variant, ?string $size, string $key): void
    {
        $template = $size === null
            ? '<x-button variant="'.$variant.'">X</x-button>'
            : '<x-button variant="'.$variant.'" size="'.$size.'">X</x-button>';

        $this->assertSame($this->ui($key), $this->render($template)->filter('button')->attr('class'));
    }

    public function test_badge_and_alert_classes_are_the_ones_scripts_use(): void
    {
        foreach (['neutral', 'brand', 'accent', 'success', 'warning', 'danger', 'info'] as $tone) {
            $badge = $this->render('<x-badge tone="'.$tone.'">Lulus</x-badge>')->filter('span');

            $this->assertSame($this->ui("badge.{$tone}"), $badge->attr('class'), "badge {$tone}");
            $this->assertSame('Lulus', $badge->text());
        }

        $this->assertSame($this->ui('badge.neutral'), $this->render('<x-badge>X</x-badge>')->filter('span')->attr('class'));
        $this->assertSame($this->ui('badge.brand'), $this->render('<x-badge tone="primary">X</x-badge>')->filter('span')->attr('class'));

        foreach (['neutral', 'brand', 'info', 'success', 'warning', 'danger'] as $tone) {
            $this->assertSame($this->ui("alert.{$tone}"), $this->render('<x-alert tone="'.$tone.'">Pesan</x-alert>')->filter('div')->attr('class'), "alert {$tone}");
        }

        $alert = $this->render('<x-alert tone="error" icon title="Gagal dimuat." role="alert">Coba lagi.</x-alert>')->filter('[role="alert"]');

        $this->assertSame($this->ui('alert.danger').' flex gap-3', $alert->attr('class'));
        $this->assertSame($this->ui('alertIcon.danger'), $alert->filter('svg')->attr('class'));
        $this->assertSame('Gagal dimuat.', $alert->filter('p.font-medium')->text());
    }

    public function test_form_controls_use_the_script_classes_and_label_their_hints(): void
    {
        $this->assertSame($this->ui('input'), $this->render('<x-input id="q" />')->filter('input')->attr('class'));
        $this->assertSame('text', $this->render('<x-input id="q" />')->filter('input')->attr('type'));
        $this->assertSame($this->ui('inputWithIcon'), $this->render('<x-input icon type="search" />')->filter('input')->attr('class'));
        $this->assertSame($this->ui('inputWithToggle'), $this->render('<x-input toggle type="password" />')->filter('input')->attr('class'));
        $this->assertSame($this->ui('fileInput'), $this->render('<x-input type="file" />')->filter('input')->attr('class'));
        $this->assertSame($this->ui('select'), $this->render('<x-select><option>A</option></x-select>')->filter('select')->attr('class'));
        $this->assertSame($this->ui('textarea'), $this->render('<x-textarea></x-textarea>')->filter('textarea')->attr('class'));
        $this->assertSame($this->ui('label'), $this->render('<x-label for="q">Cari</x-label>')->filter('label')->attr('class'));

        $field = $this->render('<x-field label="IPK target" for="target" hint="Antara 0 dan 4" error="Terlalu tinggi"><x-input id="target" /></x-field>');

        $this->assertSame($this->ui('field'), $field->filter('div')->attr('class'));
        $this->assertSame('target', $field->filter('label')->attr('for'));
        $this->assertSame($this->ui('hint'), $field->filter('#target-hint')->attr('class'));
        $this->assertSame($this->ui('error'), $field->filter('#target-error')->attr('class'));
    }

    public function test_table_skeleton_and_modal_parts_use_the_script_classes(): void
    {
        $table = $this->render('<x-table id="t"><thead><tr><x-table.th>Kode</x-table.th></tr></thead><tbody><x-table.tr><x-table.td>A</x-table.td></x-table.tr></tbody></x-table>');

        $this->assertSame($this->ui('tableWrap'), $table->filter('div')->attr('class'));
        $this->assertSame($this->ui('table'), $table->filter('table#t')->attr('class'));
        $this->assertSame($this->ui('th'), $table->filter('th')->attr('class'));
        $this->assertSame('col', $table->filter('th')->attr('scope'));
        $this->assertSame($this->ui('tr'), $table->filter('tbody tr')->attr('class'));
        $this->assertSame($this->ui('td'), $table->filter('td')->attr('class'));

        $skeleton = $this->render('<x-skeleton class="h-4 w-4/5" />')->filter('[data-slot="skeleton"]');
        $this->assertSame($this->ui('skeleton').' h-4 w-4/5', $skeleton->attr('class'));
        $this->assertSame('true', $skeleton->attr('aria-hidden'));

        $modal = $this->render('<x-modal id="m"><x-modal.backdrop /><x-modal.panel size="lg"><x-modal.header><x-modal.title id="mt">Judul</x-modal.title><x-modal.close /></x-modal.header><x-modal.body>Isi</x-modal.body></x-modal.panel></x-modal>');

        // Hidden by the `hidden` class alone: the overlay has no display class that could win over it.
        $this->assertSame($this->ui('modal').' hidden', $modal->filter('#m')->attr('class'));
        $this->assertSame('true', $modal->filter('#m')->attr('aria-hidden'));
        $this->assertSame($this->ui('modalPanelLg'), $modal->filter('#m > div')->last()->attr('class'));
        $this->assertSame($this->ui('modalTitle'), $modal->filter('#mt')->attr('class'));
        $this->assertSame('Tutup', $modal->filter('button')->attr('aria-label'));
        $this->assertSame($this->ui('modalClose'), $modal->filter('button')->attr('class'));
    }

    public function test_list_row_spaces_a_divided_list(): void
    {
        $this->assertSame(
            $this->ui('listRowDivided').' border-b',
            $this->render('<x-list-row class="border-b">Isi</x-list-row>')->filter('div')->attr('class')
        );
    }

    public function test_navigation_items_mark_the_current_page_and_hold_their_badge(): void
    {
        $icon = '<svg class="h-5 w-5"></svg>';
        $bottom = $this->render('<x-nav.bottom-item href="/class" label="Kuliah" :icon="$icon" badge="kuliah" :active="true" />', ['icon' => $icon])->filter('a');

        $this->assertSame($this->ui('bottomNavItem'), $bottom->attr('class'));
        $this->assertSame('page', $bottom->attr('aria-current'));
        $this->assertSame('kuliah', $bottom->attr('data-nav-slot'));
        $this->assertCount(1, $bottom->filter('[data-nav-icon] > svg + span.hidden[data-nav-badge="kuliah"]'));
        $this->assertSame($this->ui('navBadgeBottom').' hidden', $bottom->filter('[data-nav-badge]')->attr('class'));

        $rail = $this->render('<x-nav.rail-item href="/dashboard" label="Beranda" icon="home" rail-slot="beranda" :active="true" />')->filter('a');

        $this->assertSame($this->ui('railItem'), $rail->attr('class'));
        $this->assertSame('page', $rail->attr('aria-current'));
        $this->assertSame('beranda', $rail->attr('data-rail-slot'));
        $this->assertSame('Beranda', $rail->attr('aria-label'));
        $this->assertSame('Beranda', $rail->attr('title'));

        $sub = $this->render('<x-nav.sidebar-subitem href="/exam" label="Ujian" icon="exam" sub-slot="exam" :active="true" />')->filter('a');

        $this->assertSame($this->ui('sidebarSubItem'), $sub->attr('class'));
        $this->assertSame('location', $sub->attr('aria-current'));
        $this->assertSame('exam', $sub->attr('data-sidebar-subitem'));
        $this->assertSame($this->ui('sidebarSubItemIcon'), $sub->filter('[data-slot="sidebar-subicon"]')->attr('class'));
    }

    public function test_shell_pieces_use_the_script_classes(): void
    {
        $this->assertSame($this->ui('toastHost'), $this->render('<x-toast-host />')->filter('#notice-host')->attr('class'));
        $this->assertSame($this->ui('toastHostGuest'), $this->render('<x-toast-host guest />')->filter('#notice-host')->attr('class'));
        $this->assertSame($this->ui('stickyAction'), $this->render('<x-sticky-action>A</x-sticky-action>')->filter('[data-sticky-action]')->attr('class'));
        $this->assertSame($this->ui('avatar'), $this->render('<x-avatar>M</x-avatar>')->filter('span')->attr('class'));
        $this->assertSame($this->ui('avatarLg'), $this->render('<x-avatar size="lg">M</x-avatar>')->filter('span')->attr('class'));
        $this->assertSame($this->ui('spinner'), $this->render('<x-spinner />')->filter('span')->attr('class'));
        $this->assertSame($this->ui('statGrid').' grid-cols-3', $this->render('<x-stat-strip class="grid-cols-3"></x-stat-strip>')->filter('div')->attr('class'));
        $this->assertSame($this->ui('iconTile.success').' size-9', $this->render('<x-icon-tile tone="success" class="size-9">A</x-icon-tile>')->filter('span')->attr('class'));

        $strip = $this->render('<x-sync-strip />');

        $this->assertSame($this->ui('syncStrip.base'), $strip->filter('[data-sync-alert]')->attr('class'));
        $this->assertSame($this->ui('chipOnline'), $strip->filter('[data-connection-chip]')->attr('class'));
        $this->assertSame($this->ui('chipOnline'), $strip->filter('[data-realtime-chip]')->attr('class'));
        $this->assertSame($this->ui('button.iconSm'), $strip->filter('[data-sync-refresh]')->attr('class'));
        $this->assertSame($this->ui('spinner').' hidden', $strip->filter('[data-sync-refresh-spinner]')->attr('class'));
        $this->assertCount(0, $this->render('<x-sync-strip :refresh="false" />')->filter('[data-sync-refresh]'));
    }

    public function test_icons_and_sections_are_the_ones_scripts_use(): void
    {
        $icons = require resource_path('views/components/data/icons.php');
        $sections = require resource_path('views/components/data/sections.php');

        $this->assertSame($this->uiArray('icons'), $icons);

        foreach ($sections['sections'] as $key => $section) {
            $this->assertSame(['label', 'icon'], array_keys($section), $key);
            $this->assertArrayHasKey($section['icon'], $icons, $key);
        }

        foreach ($sections['pages'] as $routeName => $page) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($routeName), $routeName);
            $this->assertArrayHasKey($page['section'], $sections['sections'], $routeName);
            $this->assertNotEmpty($page['crumbs'], $routeName);

            if (isset($page['icon'])) {
                $this->assertArrayHasKey($page['icon'], $icons, $routeName);
            }

            foreach ($page['crumbs'] as $crumb) {
                if (isset($crumb[1])) {
                    $this->assertTrue(\Illuminate\Support\Facades\Route::has($crumb[1]), $routeName.' → '.$crumb[1]);
                }
            }
        }
    }

    public function test_icon_draws_its_paths_at_a_size_and_nothing_for_an_unknown_name(): void
    {
        $icon = $this->render('<x-icon name="calendar" size="sm" class="text-ink-subtle" />')->filter('svg');

        $this->assertSame($this->ui('iconSize.sm').' text-ink-subtle', $icon->attr('class'));
        $this->assertSame('16', $icon->attr('width'));
        $this->assertSame('true', $icon->attr('aria-hidden'));
        $this->assertSame('calendar', $icon->attr('data-icon'));
        $this->assertCount(4, $icon->filter('path'));
        $this->assertSame($this->ui('iconSize.md'), $this->render('<x-icon name="home" />')->filter('svg')->attr('class'));
        $this->assertSame($this->ui('iconSize.xl'), $this->render('<x-icon name="home" size="xl" />')->filter('svg')->attr('class'));
        $this->assertCount(0, $this->render('<x-icon name="no-such-icon" />')->filter('svg'));
    }

    public function test_page_hero_is_a_plain_header_with_its_title_and_slots(): void
    {
        $hero = $this->render(<<<'BLADE'
            <x-page-hero title="KRS & Perwalian" subtitle="Status KRS" accent="emerald" icon="list-check" :breadcrumb="['Akademik', 'KRS & Perwalian']" class="print:hidden">
                <x-slot:actions><x-button variant="secondary" size="sm">Cetak</x-button></x-slot:actions>
                <x-slot:meta id="krs-meta"><span>Diperbarui</span></x-slot:meta>
                <p data-after></p>
            </x-page-hero>
            BLADE)->filter('header');

        $this->assertSame($this->ui('pageHero').' print:hidden', $hero->attr('class'));
        $this->assertSame('page-hero', $hero->attr('data-slot'));
        $this->assertSame($this->ui('pageHeroRow'), $hero->filter('header > div')->first()->attr('class'));
        $this->assertSame($this->ui('pageHeroTitle'), $hero->filter('h1')->attr('class'));
        $this->assertSame($this->ui('pageTitle'), $this->ui('pageHeroTitle'), 'The hero h1 is the plain page title.');
        $this->assertSame('KRS & Perwalian', $hero->filter('h1')->text());
        $this->assertSame($this->ui('pageHeroSubtitle'), $hero->filter('h1 + p')->attr('class'));
        $this->assertCount(1, $hero->filter('h1 + p + p[data-after]'));
        $this->assertSame($this->ui('pageHeroMeta'), $hero->filter('#krs-meta')->attr('class'));
        $this->assertSame($this->ui('pageHeroActions'), $hero->filter('[data-slot="page-hero-actions"]')->attr('class'));
        $this->assertSame('Cetak', trim($hero->filter('[data-slot="page-hero-actions"] button')->text()));

        // No band, no icon tile, no breadcrumb line: the accent and the icon are ignored.
        $this->assertCount(0, $hero->filter('[data-slot="page-hero-band"]'));
        $this->assertCount(0, $hero->filter('[data-slot="page-hero-icon"]'));
        $this->assertCount(0, $hero->filter('svg'));
        $this->assertCount(0, $hero->filter('nav'));
        $this->assertNull($hero->attr('accent'));
        $this->assertNull($hero->attr('icon'));
    }

    public function test_page_hero_takes_title_slots_and_tabs(): void
    {
        $hero = $this->render(<<<'BLADE'
            <x-page-hero back="/announcement" back-label="Pengumuman" :icon="false">
                <x-slot:title id="announcement-title" data-announcement-title="">Libur semester</x-slot:title>
                <x-slot:subtitle id="announcement-meta">20 November</x-slot:subtitle>
                <x-slot:icon class="size-5"><svg data-legacy-icon></svg></x-slot:icon>
                <x-slot:tabs><x-academic-menu active="krs" underline accent="emerald" /></x-slot:tabs>
            </x-page-hero>
            BLADE)->filter('header');

        $this->assertSame($this->ui('pageHero'), $hero->attr('class'));
        $this->assertSame($this->ui('pageHeroTitle'), $hero->filter('h1#announcement-title')->attr('class'));
        $this->assertSame('', $hero->filter('h1')->attr('data-announcement-title'));
        $this->assertSame($this->ui('pageHeroSubtitle'), $hero->filter('#announcement-meta')->attr('class'));
        // Back links and icon slots from older pages are ignored.
        $this->assertCount(0, $hero->filter('header > a'));
        $this->assertCount(0, $hero->filter('[data-legacy-icon]'));

        $tabs = $hero->filter('[data-slot="page-hero-tabs"]');
        $this->assertSame($this->ui('pageHeroTabs'), $tabs->attr('class'));
        $this->assertSame($this->ui('underlineTabs'), $tabs->filter('nav[aria-label="Menu akademik"] > ul')->attr('class'));
        $this->assertSame('page', $tabs->filter('a[href="'.route('krs.index').'"]')->attr('aria-current'));
        // Every tab carries the same neutral classes; aria-current draws the ink underline. Text only.
        $this->assertSame([$this->ui('underlineTab')], array_values(array_unique($tabs->filter('a')->extract(['class']))));
        $this->assertCount(0, $tabs->filter('svg'));
        $this->assertNull($tabs->filter('nav[aria-label="Menu akademik"]')->attr('class') ?: null, 'The underlined menu drops the bar\'s bottom margin.');
    }

    public function test_page_hero_hands_its_breadcrumb_to_the_top_bar(): void
    {
        $this->onRoute('/grades');

        $hero = $this->render('<x-page-hero title="Nilai (KHS)" />')->filter('header');

        $this->assertCount(0, $hero->filter('nav'));
        $this->assertCount(0, $hero->filter('svg'));

        // A breadcrumb the page passes becomes the top bar's, unless the page set its own.
        $page = $this->render('<x-page-hero title="Riwayat KRS" :breadcrumb="[[\'label\' => \'Arsip WeCIC\', \'href\' => \'/archive\'], \'Riwayat KRS\']" /><div id="top-bar">@yield(\'breadcrumb\')</div>');
        $topBar = $page->filter('#top-bar nav[aria-label="Lokasi halaman"]');

        $this->assertSame($this->ui('breadcrumbMd'), $topBar->attr('class'));
        $this->assertSame('/archive', $topBar->filter('a')->attr('href'));
        $this->assertSame($this->ui('breadcrumbLink'), $topBar->filter('a')->attr('class'));
        $this->assertSame('Riwayat KRS', $topBar->filter('[aria-current="page"]')->text());
        $this->assertCount(0, $topBar->filter('svg'));
        $this->assertCount(0, $page->filter('header nav'));

        // The route's crumbs, for the top bar.
        $crumbs = $this->render('<x-breadcrumb size="md" icon />')->filter('nav');
        $this->assertSame(['Akademik', 'Nilai'], $crumbs->filter('li > :not([aria-hidden])')->each(fn (Crawler $crumb): string => $crumb->text()));
        $this->assertSame(route('krs.index'), $crumbs->filter('a')->attr('href'));
        $this->assertSame($this->ui('breadcrumbCurrent'), $crumbs->filter('[aria-current="page"]')->attr('class'));
        $this->assertSame($this->ui('breadcrumbSeparator'), $crumbs->filter('li')->last()->filter('span')->first()->attr('class'));
        $this->assertCount(0, $crumbs->filter('svg'));
    }

    public function test_metric_empty_state_and_icon_tile_ignore_a_legacy_accent(): void
    {
        $metric = $this->render('<x-metric label="IPK" icon="trending-up" accent="emerald"><x-slot:value id="ipk">3,80</x-slot:value></x-metric>')->filter('[data-slot="metric"]');

        $this->assertSame($this->ui('metric'), $metric->attr('class'));
        $this->assertCount(0, $metric->filter('svg'));
        $this->assertCount(0, $metric->filter('[aria-hidden="true"]'));
        $this->assertSame('3,80', $metric->filter('#ipk')->text());

        $empty = $this->render('<x-empty-state title="Belum ada pengumuman" icon="announcement" accent="orange" />')->filter('[data-slot="empty"]');

        $this->assertSame($this->ui('emptyIcon'), $empty->filter('[aria-hidden="true"]')->attr('class'));
        $this->assertSame('announcement', $empty->filter('svg')->attr('data-icon'));
        $this->assertSame($this->ui('emptyIcon'), $this->render('<x-empty-state title="Kosong"><x-slot:icon><svg></svg></x-slot:icon></x-empty-state>')->filter('div[aria-hidden="true"]')->attr('class'));
        // A small neutral icon: no chip around it.
        $this->assertDoesNotMatchRegularExpression('/\b(bg-|rounded-|size-)/', $this->ui('emptyIcon'));

        $tile = $this->render('<x-icon-tile accent="teal" icon="library" class="size-10" />')->filter('span');

        $this->assertSame($this->ui('iconTile.neutral').' size-10', $tile->attr('class'));
        $this->assertSame('library', $tile->filter('svg')->attr('data-icon'));
        $this->assertSame($this->ui('iconTileBaseFull').' bg-surface text-ink-muted', $this->render('<x-icon-tile accent="rose" shape="full">A</x-icon-tile>')->filter('span')->attr('class'));
        $this->assertSame($this->ui('iconTile.brand'), $this->render('<x-icon-tile tone="brand" accent="teal">A</x-icon-tile>')->filter('span')->attr('class'));
    }

    public function test_section_heading_takes_a_count_a_description_and_a_link(): void
    {
        $section = $this->render('<x-section title="Tugas" title-id="tasks" count="3" description="Tenggat terdekat dulu" href="/class" icon="exam" accent="violet">Isi</x-section>')->filter('section');
        $heading = $section->filter('[data-slot="section-heading"]');

        $this->assertSame('tasks', $section->attr('aria-labelledby'));
        $this->assertSame($this->ui('sectionHeading').' mb-3', $heading->attr('class'));
        $this->assertSame($this->ui('sectionTitleRow'), $heading->filter('h2#tasks')->ancestors()->first()->attr('class'));
        $this->assertSame($this->ui('sectionTitle'), $heading->filter('h2#tasks')->attr('class'));
        $this->assertSame($this->ui('sectionCount'), $heading->filter('[data-slot="section-count"]')->attr('class'));
        $this->assertSame('3', $heading->filter('[data-slot="section-count"]')->text());
        $this->assertSame($this->ui('sectionDescription'), $heading->filter('p')->attr('class'));
        $this->assertSame('/class', $heading->filter('a')->attr('href'));
        $this->assertSame($this->ui('sectionLink'), $heading->filter('a')->attr('class'));
        $this->assertSame('Lihat semua', trim($heading->filter('a')->text()));

        // Text only: the legacy icon draws no tile, and the count is muted text after a dot, not a pill.
        $this->assertCount(0, $heading->filter('span[aria-hidden="true"]'));
        $this->assertCount(0, $heading->filter('svg[data-icon="exam"]'));
        $this->assertStringContainsString("before:content-['·']", $this->ui('sectionCount'));
        $this->assertStringContainsString('empty:hidden', $this->ui('sectionCount'));
        $this->assertDoesNotMatchRegularExpression('/\b(bg-|rounded-)/', $this->ui('sectionCount'));
        $this->assertStringContainsString('text-ink-muted', $this->ui('sectionLink'));

        $slotted = $this->render('<x-section title="Hari ini" title-id="today" heading-class="mb-0"><x-slot:count class="hidden" data-today-count>0</x-slot:count>'."\n".'Isi</x-section>');

        $this->assertSame($this->ui('sectionHeading').' mb-0', $slotted->filter('[data-slot="section-heading"]')->attr('class'));
        $this->assertSame($this->ui('sectionCount').' hidden', $slotted->filter('[data-today-count]')->attr('class'));

        // An icon alone no longer makes a heading row.
        $this->assertSame($this->ui('sectionTitle').' mb-3', $this->render('<x-section title="Tugas" title-id="t" icon="exam">Isi</x-section>')->filter('section > h2')->attr('class'));
    }

    public function test_list_rows_fill_a_flush_list_with_leading_and_trailing_parts(): void
    {
        $list = $this->render(<<<'BLADE'
            <x-list id="l">
                <x-list-row flush href="/announcement/1">
                    <x-slot:leading><x-icon-tile icon="announcement" class="size-10" data-slot="leading-tile" /></x-slot:leading>
                    <p class="truncate text-sm font-medium text-ink">Libur</p>
                    <x-slot:trailing><x-badge>Akademik</x-badge></x-slot:trailing>
                </x-list-row>
                <x-list-row flush>Rapat</x-list-row>
            </x-list>
            BLADE)->filter('#l');

        $this->assertSame($this->ui('list'), $list->attr('class'));

        $link = $list->filter('a[data-slot="list-row"]');
        $this->assertSame('/announcement/1', $link->attr('href'));
        $this->assertSame($this->ui('listRowFlushInteractive'), $link->attr('class'));
        $this->assertSame($this->ui('iconTile.neutral').' size-10', $link->filter('[data-slot="leading-tile"]')->attr('class'));
        $this->assertSame('announcement', $link->filter('[data-slot="leading-tile"] svg')->attr('data-icon'));
        $this->assertSame($this->ui('listRowBody'), $link->filter('[data-slot="leading-tile"] + div')->attr('class'));
        $this->assertSame($this->ui('listRowTrailing'), $link->filter('[data-slot="leading-tile"] + div + div')->attr('class'));
        $this->assertSame($this->ui('listRowChevron'), $link->filter('svg[data-icon="chevron-right"]')->attr('class'));

        $plain = $list->filter('div[data-slot="list-row"]');
        $this->assertSame($this->ui('listRowFlush'), $plain->attr('class'));
        $this->assertCount(0, $plain->filter('svg'));
    }

    public function test_split_puts_a_sticky_aside_beside_the_main_column(): void
    {
        $split = $this->render('<x-split id="s"><p>Isi</p><x-slot:aside aria-label="Ringkasan">IPK</x-slot:aside></x-split>')->filter('#s');

        $this->assertSame($this->ui('split'), $split->attr('class'));
        $this->assertSame($this->ui('splitMain'), $split->filter('[data-slot="split-main"]')->attr('class'));
        $this->assertSame($this->ui('splitAside'), $split->filter('aside')->attr('class'));
        $this->assertSame('Ringkasan', $split->filter('aside')->attr('aria-label'));

        $xl = $this->render('<x-split at="xl" id="s"><p>Isi</p><x-slot:aside>IPK</x-slot:aside></x-split>')->filter('#s');

        $this->assertSame($this->ui('splitXl'), $xl->attr('class'));
        $this->assertSame($this->ui('splitAsideXl'), $xl->filter('aside')->attr('class'));
        $this->assertSame($this->ui('splitAsideStatic'), $this->render('<x-split :sticky="false"><x-slot:aside>A</x-slot:aside></x-split>')->filter('aside')->attr('class'));
    }

    public function test_stat_strip_is_one_bordered_box_with_divider_cells(): void
    {
        $strip = $this->render('<x-stat-strip kpi id="k"><x-metric label="IPK"><x-slot:value>3,80</x-slot:value></x-metric><x-metric label="SKS"><x-slot:value>120</x-slot:value></x-metric></x-stat-strip>')->filter('#k');

        $this->assertSame($this->ui('statGridKpi'), $strip->attr('class'));
        $this->assertSame('stat-strip', $strip->attr('data-slot'));
        $this->assertCount(2, $strip->filter('[data-slot="metric"]'));
        $this->assertSame($this->ui('statGrid').' grid-cols-3', $this->render('<x-stat-strip class="grid-cols-3"></x-stat-strip>')->filter('div')->attr('class'));

        // The frame is the strip's; the 1px gap shows the border colour between the cells, however they wrap.
        foreach (['statGrid', 'statGridKpi'] as $key) {
            foreach (['gap-px', 'overflow-hidden', 'rounded-xl', 'border', 'border-border', 'bg-border'] as $class) {
                $this->assertContains($class, explode(' ', $this->ui($key)), "{$key} {$class}");
            }
            $this->assertStringNotContainsString('shadow', $this->ui($key), $key);
        }
        $this->assertStringContainsString('grid-cols-2', $this->ui('statGridKpi'));
        $this->assertStringContainsString('lg:grid-cols-4', $this->ui('statGridKpi'));
    }

    public function test_cards_have_a_border_and_no_shadow(): void
    {
        foreach (['card', 'cardInteractive'] as $key) {
            $this->assertStringContainsString('border border-border', $this->ui($key), $key);
            $this->assertStringNotContainsString('shadow', $this->ui($key), $key);
        }

        $this->assertSame($this->ui('card'), $this->render('<x-card>Isi</x-card>')->filter('[data-slot="card"]')->attr('class'));
    }

    public function test_the_class_map_has_no_gradients_and_no_section_hues(): void
    {
        $source = (string) file_get_contents(resource_path('js/app/ui.js'));

        $this->assertDoesNotMatchRegularExpression('/bg-linear|bg-gradient|from-(brand|blue|violet|emerald|amber|rose|indigo|teal|orange|sky|fuchsia|slate|zinc)/', $source);
        $this->assertDoesNotMatchRegularExpression('/\b(violet|emerald|rose|indigo|teal|orange|sky|fuchsia|slate|zinc)-\d/', $source);
        $this->ui('container');
        $this->assertNull(data_get(self::$ui, 'brandCard'));
        $this->assertNull(data_get(self::$ui, 'button.onBrand'));
        $this->assertNull(data_get(self::$ui, 'sectionAccents'));

        foreach ([
            'accents',
            'topNavLink',
            'sidebarItem',
            'sidebarItemIcon',
            'modalFooter',
            'pageHeroTabbed',
            'pageHeroBand',
            'pageHeroLead',
            'pageHeroIcon',
            'metricIcon',
            'metricIconBase',
            'sectionIconBase',
            'emptyIconBase',
        ] as $removedKey) {
            $this->assertNull(data_get(self::$ui, $removedKey), $removedKey);
        }
    }

    public function test_the_class_map_has_no_old_component_classes_and_no_built_names(): void
    {
        $source = (string) file_get_contents(resource_path('js/app/ui.js'));

        $this->assertStringNotContainsString('mycic-', $source);
        $this->assertDoesNotMatchRegularExpression('/\$\{|\'\s*\+|\+\s*\'/', $source, 'Every class string is written out in full, so Tailwind sees it.');
        $this->assertDoesNotMatchRegularExpression('/\.mycic-[a-z]/', (string) file_get_contents(resource_path('css/app.css')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $template, array $data = []): Crawler
    {
        return new Crawler('<!DOCTYPE html><html><body>'.$this->blade($template, $data).'</body></html>');
    }

    /**
     * Makes the current request the one for `$uri`, with its route, as a
     * page's components see it.
     */
    private function onRoute(string $uri): void
    {
        $request = Request::create($uri);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        $this->app->instance('request', $request);
    }

    /**
     * A map from resources/js/app/ui.js by its dotted key (`icons`).
     *
     * @return array<string, mixed>
     */
    private function uiArray(string $key): array
    {
        $this->ui('container');
        $value = data_get(self::$ui, $key);
        $this->assertIsArray($value, "app.ui.{$key} exists");

        return $value;
    }

    /**
     * A class string from resources/js/app/ui.js by its dotted key
     * (`button.primary`), read through Node so the test compares with the
     * very object scripts get as `app.ui`.
     */
    private function ui(string $key): string
    {
        if (self::$ui === null) {
            $node = (new ExecutableFinder)->find('node');

            if ($node === null) {
                $this->markTestSkipped('Node.js is not installed here; the class map is read through it.');
            }

            $result = Process::path(base_path())->timeout(30)->run([
                $node,
                '--input-type=module',
                '-e',
                "import { ui } from './resources/js/app/ui.js'; process.stdout.write(JSON.stringify(ui));",
            ]);

            $this->assertTrue($result->successful(), $result->errorOutput());
            self::$ui = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        }

        $value = data_get(self::$ui, $key);
        $this->assertIsString($value, "app.ui.{$key} exists");

        return $value;
    }
}
