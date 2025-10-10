<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\CampusScrapingException;
use App\Services\CampusScrapingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class SipandaiExtractor extends CampusScrapingService
{
    /**
     * Extract SIPANDAI integration data
     */
    public function extractSipandaiData(string $sessionToken, array $options = []): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting SIPANDAI data', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'sipandai_data_' . md5($sessionToken . serialize($options));
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached SIPANDAI data');
                return json_decode($cachedData, true);
            }

            // Try different possible SIPANDAI page URLs
            $sipandaiUrls = $this->getSipandaiPageUrls($options);
            $sipandaiData = null;

            foreach ($sipandaiUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    // Check if this page contains SIPANDAI data
                    if ($this->containsSipandaiData($crawler)) {
                        $sipandaiData = $this->extractSipandaiFromPage($crawler, $url);
                        break;
                    }
                } catch (\Exception $e) {
                    $this->logScraping('Failed to access SIPANDAI page', ['url' => $url, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            if (!$sipandaiData) {
                throw new CampusScrapingException::elementNotFound('SIPANDAI data', 'any SIPANDAI page');
            }

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($sipandaiData));

            $this->logScraping('SIPANDAI data extracted successfully', [
                'data_sections' => array_keys($sipandaiData),
            ]);

            return $sipandaiData;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting SIPANDAI data', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'SIPANDAI', $e->getMessage());
        }
    }

    /**
     * Get possible SIPANDAI page URLs
     */
    private function getSipandaiPageUrls(array $options): array
    {
        $baseUrl = config('campus.base_url');
        $section = $options['section'] ?? null;

        $urls = [
            $baseUrl . '/portal/mahasiswa/?mod=sipandai',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=sipandai',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=integration',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=pddikti', // PDDikti integration
        ];

        // Add section-specific URLs if provided
        if ($section) {
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=sipandai&section=' . $section;
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=sipandai&section=' . $section;
        }

        return $urls;
    }

    /**
     * Check if page contains SIPANDAI data
     */
    private function containsSipandaiData(Crawler $crawler): bool
    {
        $sipandaiIndicators = [
            'sipandai',
            'pddikti',
            'integrasi',
            'integration',
            'tracer study',
            'alumni',
            'study tracer',
            'kuesioner',
            'questionnaire',
            'data lengkap',
        ];

        $pageText = strtolower($crawler->text());

        foreach ($sipandaiIndicators as $indicator) {
            if (Str::contains($pageText, $indicator)) {
                return true;
            }
        }

        // Check for forms or data integration elements
        $hasIntegrationElements = $crawler->filter('form, .integration-form, .data-form')->count() > 0;

        return $hasIntegrationElements;
    }

    /**
     * Extract SIPANDAI data from the page
     */
    private function extractSipandaiFromPage(Crawler $crawler, string $url): array
    {
        $sipandaiData = [
            'student_profile' => $this->extractStudentProfile($crawler),
            'academic_data' => $this->extractAcademicData($crawler),
            'personal_data' => $this->extractPersonalData($crawler),
            'family_data' => $this->extractFamilyData($crawler),
            'contact_data' => $this->extractContactData($crawler),
            'documents' => $this->extractDocuments($crawler),
            'questionnaires' => $this->extractQuestionnaires($crawler),
            'status_info' => $this->extractStatusInfo($crawler),
            'actions' => $this->extractAvailableActions($crawler),
            'extracted_at' => now()->toISOString(),
            'source_url' => $url,
        ];

        return $sipandaiData;
    }

    /**
     * Extract student profile information
     */
    private function extractStudentProfile(Crawler $crawler): array
    {
        $profile = [];

        // Look for profile sections
        $profileSelectors = [
            '.student-profile',
            '.profile-info',
            '.biodata',
            '.data-diri',
        ];

        foreach ($profileSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $profile = array_merge($profile, $this->extractFormData($element));
            }
        }

        // Extract specific profile fields
        $profile['full_name'] = $this->extractText($crawler, [
            '.full-name',
            '.nama-lengkap',
            'input[name="nama"]',
            '.profile-name',
        ]);

        $profile['student_id'] = $this->extractText($crawler, [
            '.nim',
            '.student-id',
            'input[name="nim"]',
            '.registration-number',
        ]);

        $profile['birth_date'] = $this->extractText($crawler, [
            '.birth-date',
            '.tanggal-lahir',
            'input[name="tanggal_lahir"]',
            '.dob',
        ]);

        $profile['birth_place'] = $this->extractText($crawler, [
            '.birth-place',
            '.tempat-lahir',
            'input[name="tempat_lahir"]',
            '.pob',
        ]);

        return array_filter($profile);
    }

    /**
     * Extract academic data
     */
    private function extractAcademicData(Crawler $crawler): array
    {
        $academic = [];

        $academicSelectors = [
            '.academic-data',
            '.data-akademik',
            '.study-info',
        ];

        foreach ($academicSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $academic = array_merge($academic, $this->extractFormData($element));
            }
        }

        // Extract specific academic fields
        $academic['faculty'] = $this->extractText($crawler, [
            '.faculty',
            '.fakultas',
            'select[name="fakultas"]',
        ]);

        $academic['study_program'] = $this->extractText($crawler, [
            '.study-program',
            '.program-studi',
            'select[name="program_studi"]',
        ]);

        $academic['entry_year'] = $this->extractText($crawler, [
            '.entry-year',
            '.tahun-masuk',
            'input[name="tahun_masuk"]',
        ]);

        return array_filter($academic);
    }

    /**
     * Extract personal data
     */
    private function extractPersonalData(Crawler $crawler): array
    {
        $personal = [];

        $personalSelectors = [
            '.personal-data',
            '.data-pribadi',
            '.personal-info',
        ];

        foreach ($personalSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $personal = array_merge($personal, $this->extractFormData($element));
            }
        }

        // Extract specific personal fields
        $personal['gender'] = $this->extractText($crawler, [
            '.gender',
            '.jenis-kelamin',
            'select[name="jenis_kelamin"]',
        ]);

        $personal['religion'] = $this->extractText($crawler, [
            '.religion',
            '.agama',
            'select[name="agama"]',
        ]);

        $personal['nationality'] = $this->extractText($crawler, [
            '.nationality',
            '.kewarganegaraan',
            'select[name="kewarganegaraan"]',
        ]);

        return array_filter($personal);
    }

    /**
     * Extract family data
     */
    private function extractFamilyData(Crawler $crawler): array
    {
        $family = [];

        $familySelectors = [
            '.family-data',
            '.data-keluarga',
            '.family-info',
        ];

        foreach ($familySelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $family = array_merge($family, $this->extractFormData($element));
            }
        }

        // Extract specific family fields
        $family['father_name'] = $this->extractText($crawler, [
            '.father-name',
            '.nama-ayah',
            'input[name="nama_ayah"]',
        ]);

        $family['mother_name'] = $this->extractText($crawler, [
            '.mother-name',
            '.nama-ibu',
            'input[name="nama_ibu"]',
        ]);

        $family['parent_address'] = $this->extractText($crawler, [
            '.parent-address',
            '.alamat-orang-tua',
            'textarea[name="alamat_orang_tua"]',
        ]);

        return array_filter($family);
    }

    /**
     * Extract contact data
     */
    private function extractContactData(Crawler $crawler): array
    {
        $contact = [];

        $contactSelectors = [
            '.contact-data',
            '.data-kontak',
            '.contact-info',
        ];

        foreach ($contactSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $contact = array_merge($contact, $this->extractFormData($element));
            }
        }

        // Extract specific contact fields
        $contact['email'] = $this->extractText($crawler, [
            '.email',
            'input[name="email"]',
            'input[type="email"]',
        ]);

        $contact['phone'] = $this->extractText($crawler, [
            '.phone',
            '.telepon',
            'input[name="telepon"]',
            'input[name="no_hp"]',
        ]);

        $contact['address'] = $this->extractText($crawler, [
            '.address',
            '.alamat',
            'textarea[name="alamat"]',
        ]);

        return array_filter($contact);
    }

    /**
     * Extract documents information
     */
    private function extractDocuments(Crawler $crawler): array
    {
        $documents = [];

        $crawler->filter('.document-item, .file-item, .upload-item')->each(function (Crawler $item) use (&$documents) {
            $name = $this->extractText($item, ['.document-name', '.file-name', '.title']);
            $status = $this->extractText($item, ['.status', '.document-status']);
            $uploadDate = $this->extractText($item, ['.upload-date', '.date']);

            if ($name) {
                $documents[] = array_filter([
                    'name' => $name,
                    'status' => $status,
                    'upload_date' => $uploadDate,
                ]);
            }
        });

        return $documents;
    }

    /**
     * Extract questionnaires/surveys
     */
    private function extractQuestionnaires(Crawler $crawler): array
    {
        $questionnaires = [];

        $crawler->filter('.questionnaire, .survey, .kuesioner')->each(function (Crawler $item) use (&$questionnaires) {
            $title = $this->extractText($item, ['.title', '.survey-title', 'h3', 'h4']);
            $description = $this->extractText($item, ['.description', '.survey-desc']);
            $status = $this->extractText($item, ['.status', '.completion-status']);
            $deadline = $this->extractText($item, ['.deadline', '.due-date']);

            if ($title) {
                $questionnaires[] = array_filter([
                    'title' => $title,
                    'description' => $description,
                    'status' => $status,
                    'deadline' => $deadline,
                ]);
            }
        });

        return $questionnaires;
    }

    /**
     * Extract status information
     */
    private function extractStatusInfo(Crawler $crawler): array
    {
        $status = [];

        $statusSelectors = [
            '.status-info',
            '.integration-status',
            '.sipandai-status',
        ];

        foreach ($statusSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $status = array_merge($status, $this->extractFormData($element));
            }
        }

        // Look for status badges or indicators
        $crawler->filter('.status-badge, .status-indicator, .badge')->each(function (Crawler $badge) use (&$status) {
            $text = trim($badge->text());
            $class = $badge->attr('class') ?? '';

            if ($text) {
                $statusType = $this->determineStatusType($class);
                $status[$statusType] = $text;
            }
        });

        return $status;
    }

    /**
     * Extract available actions
     */
    private function extractAvailableActions(Crawler $crawler): array
    {
        $actions = [];

        $crawler->filter('button, .btn, a[onclick], .action-link')->each(function (Crawler $element) use (&$actions) {
            $text = trim($element->text());
            $onclick = $element->attr('onclick');
            $href = $element->attr('href');

            if ($text && ($onclick || $href)) {
                $actions[] = [
                    'text' => $text,
                    'action' => $onclick ?: $href,
                    'type' => $element->nodeName(),
                ];
            }
        });

        return $actions;
    }

    /**
     * Extract form data from a container element
     */
    private function extractFormData(Crawler $container): array
    {
        $data = [];

        // Extract from form elements
        $container->filter('input, select, textarea')->each(function (Crawler $field) use (&$data) {
            $name = $field->attr('name');
            $value = $field->attr('value') ?? $field->text();

            if ($name) {
                $key = str_replace(['[', ']'], '_', $name);
                $data[$key] = $this->cleanText($value);
            }
        });

        // Extract from label-value pairs
        $container->filter('.form-group, .field-group, .data-row')->each(function (Crawler $group) use (&$data) {
            $label = $this->extractText($group, ['.label', 'label', '.field-label']);
            $value = $this->extractText($group, ['.value', '.field-value', 'input', 'select']);

            if ($label && $value) {
                $key = strtolower(str_replace([' ', ':'], '_', $label));
                $data[$key] = $value;
            }
        });

        return $data;
    }

    /**
     * Determine status type from CSS classes
     */
    private function determineStatusType(string $classes): string
    {
        $classes = strtolower($classes);

        if (str_contains($classes, 'success') || str_contains($classes, 'complete')) {
            return 'completion_status';
        }

        if (str_contains($classes, 'warning') || str_contains($classes, 'pending')) {
            return 'verification_status';
        }

        if (str_contains($classes, 'error') || str_contains($classes, 'incomplete')) {
            return 'error_status';
        }

        return 'general_status';
    }

    /**
     * Get SIPANDAI overview without detailed data
     */
    public function getSipandaiOverview(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);

            $sipandaiUrls = $this->getSipandaiPageUrls();

            foreach ($sipandaiUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    if ($this->containsSipandaiData($crawler)) {
                        return [
                            'has_integration' => true,
                            'student_profile_status' => $this->checkProfileCompleteness($crawler),
                            'pending_actions' => $this->countPendingActions($crawler),
                            'available_questionnaires' => $crawler->filter('.questionnaire, .survey')->count(),
                            'status_info' => $this->extractStatusInfo($crawler),
                            'source_url' => $url,
                            'extracted_at' => now()->toISOString(),
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return [
                'has_integration' => false,
                'message' => 'No SIPANDAI integration data found',
                'extracted_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            $this->logScrapingError('Getting SIPANDAI overview', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'SIPANDAI overview', $e->getMessage());
        }
    }

    /**
     * Check profile completeness
     */
    private function checkProfileCompleteness(Crawler $crawler): string
    {
        $completenessIndicators = [
            'completeness' => $this->extractText($crawler, ['.completeness', '.profile-complete']),
            'percentage' => $this->extractText($crawler, ['.percentage', '.complete-percent']),
        ];

        if ($completenessIndicators['percentage']) {
            return $completenessIndicators['percentage'];
        }

        // Check for status badges
        $statusBadge = $this->extractText($crawler, ['.status-badge', '.completion-status']);
        if ($statusBadge) {
            return $statusBadge;
        }

        return 'Unknown';
    }

    /**
     * Count pending actions
     */
    private function countPendingActions(Crawler $crawler): int
    {
        return $crawler->filter('.btn-warning, .btn-primary, .action-required, .pending')->count();
    }
}