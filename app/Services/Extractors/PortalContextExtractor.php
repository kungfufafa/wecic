<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Portal-wide context read from the Portal Mahasiswa dashboard (F01): the
 * academic period (K1), the guide PDFs (K12), the VMTS questionnaire popup
 * (F02), the scrolling notice (K5) and the sidebar menu as a baseline for
 * spotting new portal menus.
 *
 * The sidebar's SiPANDAI single-sign-on form carries the NIM and password
 * hashes; only its presence is recorded, never its fields.
 */
class PortalContextExtractor extends PortalScrapingService
{
    private const SSO_MENU_LABEL = 'SiPANDAI';

    /**
     * @return array{
     *     academic_period: array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: 'dashboard'}|null,
     *     server_date: string|null,
     *     server_date_label: string|null,
     *     guide_links: list<array{label: string, path: string, filename: string}>,
     *     questionnaire: array{available: bool, title: string|null, academic_year: string|null, description: string|null, details: list<string>},
     *     notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null,
     *     sidebar_menu: list<array{label: string, href_act: string|null}>,
     *     extracted_at: string,
     *     _sync: array{data_origin: string, requested_fresh: bool, synced_at: string}
     * }
     */
    public function extractPortalContextData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting portal context', ['session_token' => $sessionToken]);

            return $this->rememberResource('portal_context', [], function (): array {
                $url = PortalEndpointRegistry::url('dashboard');
                $html = $this->get($url);

                $this->ensureNotLoginPage($html, $url);

                $context = $this->parsePortalContext($html);

                if ($context['academic_period'] === null && $context['sidebar_menu'] === []) {
                    throw PortalScrapingException::dataExtractionFailed('portal context', 'Dashboard has neither an academic period nor a sidebar menu');
                }

                $this->logScraping('Portal context extracted successfully', [
                    'academic_year' => $context['academic_period']['academic_year'] ?? null,
                    'term' => $context['academic_period']['term'] ?? null,
                    'guide_count' => count($context['guide_links']),
                    'questionnaire_available' => $context['questionnaire']['available'],
                    'menu_count' => count($context['sidebar_menu']),
                ]);

                return $context;
            });
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting portal context', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('portal context', $e->getMessage());
        }
    }

    /**
     * Parse dashboard HTML that was already downloaded (for example by the
     * login or keep-alive request) without another portal request
     *
     * @return array{
     *     academic_period: array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: 'dashboard'}|null,
     *     server_date: string|null,
     *     server_date_label: string|null,
     *     guide_links: list<array{label: string, path: string, filename: string}>,
     *     questionnaire: array{available: bool, title: string|null, academic_year: string|null, description: string|null, details: list<string>},
     *     notice: array{text: string, dates: list<string>, is_future: bool, kind: string}|null,
     *     sidebar_menu: list<array{label: string, href_act: string|null}>,
     *     extracted_at: string
     * }
     */
    public function parsePortalContext(string $html): array
    {
        $crawler = $this->createCrawler($html);
        $content = $this->mainContent($crawler);
        [$serverDate, $serverDateLabel] = $this->extractServerDate($crawler);

        return [
            'academic_period' => $this->extractAcademicPeriod($crawler),
            'server_date' => $serverDate,
            'server_date_label' => $serverDateLabel,
            'guide_links' => $this->extractGuideLinks($crawler),
            'questionnaire' => $this->extractQuestionnaire($content),
            'notice' => $this->extractPortalNotice($content, 'auto'),
            'sidebar_menu' => $this->extractSidebarMenu($crawler),
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Academic year and term from a portal heading such as
     * "PORTAL MyCIC T.A 2026/2027 SEMESTER GANJIL" (K1); public so pages
     * that already hold a heading can reuse it without another request
     *
     * @return array{label: string, academic_year: string, term: 'ganjil'|'genap'|null}|null
     */
    public function parseAcademicPeriod(string $text): ?array
    {
        return parent::parseAcademicPeriod($text);
    }

    /**
     * @return array{label: string, academic_year: string, term: 'ganjil'|'genap'|null, source: 'dashboard'}|null
     */
    private function extractAcademicPeriod(Crawler $crawler): ?array
    {
        $brands = $crawler->filter('.navbar-header a.navbar-brand');

        if ($brands->count() === 0) {
            $brands = $crawler->filter('a.navbar-brand');
        }

        foreach ($brands->each(fn (Crawler $brand): string => $brand->text('')) as $text) {
            $period = $this->parseAcademicPeriod($text);

            if ($period !== null) {
                return [...$period, 'source' => 'dashboard'];
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function extractServerDate(Crawler $crawler): array
    {
        $logo = $crawler->filter('.sidebar .logo a.simple-text');

        if ($logo->count() === 0) {
            return [null, null];
        }

        $label = $this->normalizePortalText($logo->first()->text(''));
        $date = $this->parseIndonesianDate($label);

        if ($date === null) {
            return [null, null];
        }

        return [$date->toDateString(), $label];
    }

    /**
     * PDF guides from the navbar dropdowns, as paths the campus file proxy
     * (`/api/support/download?path=`) accepts
     *
     * @return list<array{label: string, path: string, filename: string}>
     */
    private function extractGuideLinks(Crawler $crawler): array
    {
        $links = [];

        $crawler->filter('.navbar .dropdown-menu a[href]')->each(function (Crawler $link) use (&$links): void {
            $href = trim((string) $link->attr('href'));

            if (preg_match('/\.pdf$/i', (string) parse_url(Str::before($href, '#'), PHP_URL_PATH)) !== 1) {
                return;
            }

            $path = $this->campusFilePath($href);

            if ($path === null) {
                $this->logScraping('Skipping guide link outside the campus file allowlist', ['href' => $href]);

                return;
            }

            $filename = basename($path);
            $label = $this->normalizePortalText($link->text(''));

            $links[$path] ??= [
                'label' => $label !== '' ? $label : $filename,
                'path' => $path,
                'filename' => $filename,
            ];
        });

        return array_values($links);
    }

    /**
     * The VMTS questionnaire popup (F02); its form action and submit button
     * are a write payload and are never returned
     *
     * @return array{available: bool, title: string|null, academic_year: string|null, description: string|null, details: list<string>}
     */
    private function extractQuestionnaire(Crawler $content): array
    {
        $form = $content->filter('form[action*="kuesioner"]');

        if ($form->count() === 0) {
            $form = $content->filter('.popup-wrapper form.popup-form');
        }

        if ($form->count() === 0) {
            return [
                'available' => false,
                'title' => null,
                'academic_year' => null,
                'description' => null,
                'details' => [],
            ];
        }

        $form = $form->first();
        $title = $this->firstNonEmptyText($form, ['h4 center', 'h4', 'h3', 'h2']);
        $academicYear = $title !== null && preg_match('/Tahun\s+Akademik\s+(\d{4})\s*\/\s*(\d{4})/iu', $title, $matches) === 1
            ? $matches[1].'/'.$matches[2]
            : null;

        $details = array_values(array_filter(
            $form->filter('p[align="justify"]')->each(fn (Crawler $paragraph): string => $this->normalizePortalText($paragraph->text(''))),
            static fn (string $paragraph): bool => $paragraph !== ''
        ));

        return [
            'available' => true,
            'title' => $title ?? 'Kuesioner Pemahaman VMTS',
            'academic_year' => $academicYear,
            'description' => $this->firstNonEmptyText($form, ['h5']) ?? ($details[0] ?? null),
            'details' => $details,
        ];
    }

    /**
     * @return list<array{label: string, href_act: string|null}>
     */
    private function extractSidebarMenu(Crawler $crawler): array
    {
        $menu = [];

        $crawler->filter('.sidebar .nav > li')->each(function (Crawler $item) use (&$menu): void {
            if ($item->filter('form[action*="loginact"]')->count() > 0) {
                $menu[] = ['label' => self::SSO_MENU_LABEL, 'href_act' => null];

                return;
            }

            $link = $item->filter('a[href]');

            if ($link->count() === 0) {
                return;
            }

            $link = $link->first();
            $labelNode = $link->filter('p');
            $label = $this->normalizePortalText(($labelNode->count() > 0 ? $labelNode->first() : $link)->text(''));

            if ($label === '') {
                return;
            }

            $menu[] = ['label' => $label, 'href_act' => $this->queryAct((string) $link->attr('href'))];
        });

        return $menu;
    }

    private function queryAct(string $href): ?string
    {
        $query = parse_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $parameters);
        $act = $parameters['act'] ?? null;

        return is_string($act) && trim($act) !== '' ? trim($act) : null;
    }

    /**
     * @param  list<string>  $selectors
     */
    private function firstNonEmptyText(Crawler $scope, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            foreach ($scope->filter($selector)->each(fn (Crawler $node): string => $this->normalizePortalText($node->text(''))) as $text) {
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }
}
