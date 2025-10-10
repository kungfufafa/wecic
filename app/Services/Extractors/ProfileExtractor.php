<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\CampusScrapingException;
use App\Services\CampusScrapingService;
use Symfony\Component\DomCrawler\Crawler;

class ProfileExtractor extends CampusScrapingService
{
    /**
     * Extract student profile data
     */
    public function extractProfileData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student profile data', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'student_profile_'.md5($sessionToken);
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached profile data');

                return json_decode($cachedData, true);
            }

            // Get profile HTML
            $profileUrl = config('campus.profile_url');
            $html = $this->get($profileUrl);
            $crawler = $this->createCrawler($html);

            // Extract profile components
            $profileData = [
                'personal_data' => $this->extractPersonalData($crawler),
                'contact_data' => $this->extractContactData($crawler),
                'education_data' => $this->extractEducationData($crawler),
                'photo_url' => $this->extractPhotoUrl($crawler),
                'extracted_at' => now()->toISOString(),
            ];

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($profileData));

            $this->logScraping('Profile data extracted successfully', [
                'name' => $profileData['name'],
                'student_id' => $profileData['student_id'],
            ]);

            return $profileData;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting profile data', $e, ['session_token' => $sessionToken]);
            throw CampusScrapingException::dataExtractionFailed('profile', $e->getMessage());
        }
    }

    /**
     * Extract personal data from first table (Data Profil Mahasiswa)
     */
    private function extractPersonalData(Crawler $crawler): array
    {
        $personalData = [];

        try {
            // Find the first table with personal data
            $firstCard = $crawler->filter('.card')->first();
            $personalTable = $firstCard->filter('.table')->first();

            $personalTable->filter('tr')->each(function (Crawler $row) use (&$personalData) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim($cells->eq(0)->text());
                    $value = trim($cells->eq(2)->text());

                    // Clean up value by removing HTML tags and extra whitespace
                    $value = strip_tags($value);
                    $value = preg_replace('/\s+/', ' ', $value);
                    $value = trim($value);

                    switch (strtolower($label)) {
                        case 'program studi':
                            $personalData['program_studi'] = $value;
                            break;
                        case 'angkatan':
                            $personalData['angkatan'] = $value;
                            break;
                        case 'nim':
                            $personalData['nim'] = $value;
                            break;
                        case 'nama':
                            $personalData['nama'] = $value;
                            break;
                        case 'status':
                            $personalData['status'] = $value;
                            break;
                        case 'tempat lahir':
                            $personalData['tempat_lahir'] = $value;
                            break;
                        case 'tanggal lahir':
                            $personalData['tanggal_lahir'] = $value;
                            break;
                        case 'jenis kelamin':
                            $personalData['jenis_kelamin'] = $value;
                            break;
                    }
                }
            });
        } catch (\Exception $e) {
            Log::warning('Failed to extract personal data from profile', ['error' => $e->getMessage()]);
        }

        return $personalData;
    }

    /**
     * Extract contact data from second table (Data Kontak Mahasiswa)
     */
    private function extractContactData(Crawler $crawler): array
    {
        $contactData = [];

        try {
            // Find the second table with contact data
            $tables = $crawler->filter('.card .table');
            if ($tables->count() >= 2) {
                $contactTable = $tables->eq(1);

                $contactTable->filter('tr')->each(function (Crawler $row) use (&$contactData) {
                    $cells = $row->filter('td');
                    if ($cells->count() >= 3) {
                        $label = trim($cells->eq(0)->text());
                        $value = trim($cells->eq(2)->text());

                        // Clean up value
                        $value = strip_tags($value);
                        $value = preg_replace('/\s+/', ' ', $value);
                        $value = trim($value);

                        switch (strtolower($label)) {
                            case 'alamat rumah':
                                $contactData['alamat_rumah'] = $value;
                                break;
                            case 'kota':
                                $contactData['kota'] = $value;
                                break;
                            case 'propinsi':
                                $contactData['propinsi'] = $value;
                                break;
                            case 'kodepos':
                                $contactData['kodepos'] = $value;
                                break;
                            case 'telp':
                                $contactData['telp'] = $value;
                                break;
                            case 'hp':
                                $contactData['hp'] = $value;
                                break;
                            case 'alamat email':
                                $contactData['email'] = $value;
                                break;
                        }
                    }
                });
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract contact data from profile', ['error' => $e->getMessage()]);
        }

        return $contactData;
    }

    /**
     * Extract education data from third table (Data Pendidikan Mahasiswa)
     */
    private function extractEducationData(Crawler $crawler): array
    {
        $educationData = [];

        try {
            // Find the third table with education data
            $tables = $crawler->filter('.card .table');
            if ($tables->count() >= 3) {
                $educationTable = $tables->eq(2);

                $educationTable->filter('tr')->each(function (Crawler $row) use (&$educationData) {
                    $cells = $row->filter('td');
                    if ($cells->count() >= 3) {
                        $label = trim($cells->eq(0)->text());
                        $value = trim($cells->eq(2)->text());

                        // Clean up value
                        $value = strip_tags($value);
                        $value = preg_replace('/\s+/', ' ', $value);
                        $value = trim($value);

                        switch (strtolower($label)) {
                            case 'nisn':
                                $educationData['nisn'] = $value;
                                break;
                            case 'tingkat pendidikan':
                                $educationData['tingkat_pendidikan'] = $value;
                                break;
                            case 'asal sekolah':
                                $educationData['asal_sekolah'] = $value;
                                break;
                            case 'alamat':
                                $educationData['alamat_sekolah'] = $value;
                                break;
                            case 'kota':
                                $educationData['kota_sekolah'] = $value;
                                break;
                            case 'propinsi':
                                $educationData['propinsi_sekolah'] = $value;
                                break;
                            case 'negara':
                                $educationData['negara_sekolah'] = $value;
                                break;
                            case 'telp':
                                $educationData['telp_sekolah'] = $value;
                                break;
                        }
                    }
                });
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract education data from profile', ['error' => $e->getMessage()]);
        }

        return $educationData;
    }

    /**
     * Extract student name (legacy method for compatibility)
     */
    private function extractName(Crawler $crawler): ?string
    {
        $nameSelectors = [
            '.student-name',
            '.user-name',
            '.profile-name',
            '.mahasiswa-nama',
            '.nama-lengkap',
            'h1',
            '.profile-header h2',
            '.student-info .name',
            '[data-student-name]',
            '.biodata-nama',
        ];

        return $this->extractText($crawler, $nameSelectors);
    }

    /**
     * Extract student ID/NIM
     */
    private function extractStudentId(Crawler $crawler): ?string
    {
        $idSelectors = [
            '.student-id',
            '.nim',
            '.user-id',
            '.registration-number',
            '.mahasiswa-nim',
            '.student-info .id',
            '[data-student-id]',
            '.biodata-nim',
        ];

        $studentId = $this->extractText($crawler, $idSelectors);

        // Clean up common prefixes/suffixes
        if ($studentId) {
            $studentId = preg_replace('/^(NIM|ID|No\.?\s*)[:\s]*/i', '', $studentId);
            $studentId = trim($studentId);
        }

        return $studentId;
    }

    /**
     * Extract study program
     */
    private function extractStudyProgram(Crawler $crawler): ?string
    {
        $programSelectors = [
            '.study-program',
            '.program-study',
            '.jurusan',
            '.prodi',
            '.department',
            '.student-info .program',
            '.mahasiswa-prodi',
            '[data-study-program]',
        ];

        return $this->extractText($crawler, $programSelectors);
    }

    /**
     * Extract faculty
     */
    private function extractFaculty(Crawler $crawler): ?string
    {
        $facultySelectors = [
            '.faculty',
            '.fakultas',
            '.student-info .faculty',
            '.mahasiswa-fakultas',
            '[data-faculty]',
        ];

        return $this->extractText($crawler, $facultySelectors);
    }

    /**
     * Extract current semester
     */
    private function extractCurrentSemester(Crawler $crawler): ?string
    {
        $semesterSelectors = [
            '.current-semester',
            '.semester',
            '.semester-info',
            '.academic-semester',
            '.student-semester',
            '[data-semester]',
        ];

        return $this->extractText($crawler, $semesterSelectors);
    }

    /**
     * Extract GPA/IPK
     */
    private function extractGPA(Crawler $crawler): ?string
    {
        $gpaSelectors = [
            '.gpa',
            '.ipk',
            '.grade-point-average',
            '.ipk-value',
            '.gpa-value',
            '[data-gpa]',
        ];

        $gpa = $this->extractText($crawler, $gpaSelectors);

        // Extract numeric value from GPA text
        if ($gpa) {
            preg_match('/[\d.]+/', $gpa, $matches);

            return $matches[0] ?? $gpa;
        }

        return null;
    }

    /**
     * Extract total credits/SKS
     */
    private function extractTotalCredits(Crawler $crawler): ?string
    {
        $creditsSelectors = [
            '.total-credits',
            '.credits',
            '.sks',
            '.credit-hours',
            '.total-sks',
            '.sks-total',
            '[data-credits]',
        ];

        $credits = $this->extractText($crawler, $creditsSelectors);

        // Extract numeric value from credits text
        if ($credits) {
            preg_match('/[\d]+/', $credits, $matches);

            return $matches[0] ?? $credits;
        }

        return null;
    }

    /**
     * Extract email
     */
    private function extractEmail(Crawler $crawler): ?string
    {
        $emailSelectors = [
            '.email',
            '.student-email',
            '.contact-email',
            '.mahasiswa-email',
            '[data-email]',
        ];

        $email = $this->extractText($crawler, $emailSelectors);

        // Validate email format
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return null;
    }

    /**
     * Extract phone number
     */
    private function extractPhone(Crawler $crawler): ?string
    {
        $phoneSelectors = [
            '.phone',
            '.telephone',
            '.mobile',
            '.handphone',
            '.contact-phone',
            '.mahasiswa-phone',
            '[data-phone]',
        ];

        return $this->extractText($crawler, $phoneSelectors);
    }

    /**
     * Extract address
     */
    private function extractAddress(Crawler $crawler): ?string
    {
        $addressSelectors = [
            '.address',
            '.student-address',
            '.contact-address',
            '.mahasiswa-alamat',
            '[data-address]',
        ];

        return $this->extractText($crawler, $addressSelectors);
    }

    /**
     * Extract academic year
     */
    private function extractAcademicYear(Crawler $crawler): ?string
    {
        $yearSelectors = [
            '.academic-year',
            '.year',
            '.angkatan',
            '.batch',
            '.entry-year',
            '.student-year',
            '[data-academic-year]',
        ];

        return $this->extractText($crawler, $yearSelectors);
    }

    /**
     * Extract student status
     */
    private function extractStatus(Crawler $crawler): ?string
    {
        $statusSelectors = [
            '.student-status',
            '.status',
            '.academic-status',
            '.mahasiswa-status',
            '[data-status]',
        ];

        return $this->extractText($crawler, $statusSelectors);
    }

    /**
     * Extract photo URL
     */
    private function extractPhotoUrl(Crawler $crawler): ?string
    {
        try {
            // Look for images in the first card's table (photo is usually in the first row)
            $firstCard = $crawler->filter('.card')->first();
            $photoElement = $firstCard->filter('img')->first();

            if ($photoElement->count() > 0) {
                $photoUrl = $photoElement->attr('src');
                if ($photoUrl && ! empty($photoUrl)) {
                    // Make URL absolute if needed
                    if (! str_starts_with($photoUrl, 'http')) {
                        $baseUrl = config('campus.base_url');
                        $photoUrl = rtrim($baseUrl, '/').'/'.ltrim($photoUrl, '/');
                    }

                    return $photoUrl;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract photo URL', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get profile overview without detailed extraction (faster)
     */
    public function getProfileOverview(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);

            $profileUrl = config('campus.profile_url');
            $html = $this->get($profileUrl);
            $crawler = $this->createCrawler($html);

            return [
                'name' => $this->extractName($crawler),
                'student_id' => $this->extractStudentId($crawler),
                'study_program' => $this->extractStudyProgram($crawler),
                'has_photo' => $crawler->filter('.student-photo img, .profile-photo img')->count() > 0,
                'page_title' => $this->extractText($crawler, ['title', 'h1', '.page-title']),
                'extracted_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            $this->logScrapingError('Getting profile overview', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'profile overview', $e->getMessage());
        }
    }
}
