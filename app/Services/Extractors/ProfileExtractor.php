<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use App\Services\PortalUrlGuard;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ProfileExtractor extends PortalScrapingService
{
    /**
     * Extract student profile data
     */
    public function extractProfileData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student profile data', ['session_token' => $sessionToken]);

            return $this->rememberResource('profile', [], function (): array {
                $html = $this->get(PortalEndpointRegistry::url('profile'));
                $crawler = $this->createCrawler($html);

                $profileData = [
                    'personal_data' => $this->extractPersonalData($crawler),
                    'contact_data' => $this->extractContactData($crawler),
                    'education_data' => $this->extractEducationData($crawler),
                    'photo_url' => $this->extractPhotoUrl($crawler),
                    'extracted_at' => now()->toISOString(),
                ];

                $this->logScraping('Profile data extracted successfully', [
                    'has_name' => ! empty($profileData['personal_data']['nama']),
                    'has_student_id' => ! empty($profileData['personal_data']['nim']),
                ]);

                return $profileData;
            });

        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting profile data', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('profile', $e->getMessage());
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

                        // Cloudflare replaces e-mail addresses with "[email protected]"
                        // and keeps the real one encoded in data-cfemail.
                        $value = $this->extractCloudflareEmail($cells->eq(2)) ?? $value;

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
                $altText = (string) ($photoElement->attr('alt') ?? '');

                // The portal's "No Photo Available" placeholder is not a photo.
                if (preg_match('/no[-_ ]?photo|no[-_ ]?pic|no[-_ ]?foto|no[-_ ]?image|tanpa[-_ ]?foto|placeholder|default[-_]?(user|avatar|photo)|blank/i', $photoUrl.' '.$altText)) {
                    return null;
                }

                if ($photoUrl && ! empty($photoUrl)) {
                    // "../files/fotostudent/x.jpg" is relative to the profile
                    // page (/portal/mahasiswa/), not to the site root.
                    if (! str_starts_with($photoUrl, 'http')) {
                        try {
                            $photoUrl = PortalUrlGuard::resolve($photoUrl, PortalEndpointRegistry::url('profile'));
                        } catch (\Throwable) {
                            $photoUrl = rtrim((string) config('portal.base_url'), '/').'/'.ltrim($photoUrl, '/');
                        }
                    }

                    return $photoUrl;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract photo URL', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
