<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Extractors\SipandaiClassesExtractor;

/**
 * Lookup of the upstream class link parameters (`id`, `dosen_id`, `course_id`)
 * by class id or course id, built from the SIPANDAI class list as the merged
 * class list reads it.
 */
class PortalClassCatalog
{
    public function __construct(
        private readonly SipandaiClassesExtractor $sipandaiClassesExtractor,
        private readonly PortalDataCache $dataCache
    ) {}

    /**
     * Pass `fresh` to rebuild the catalog instead of reading its cache; run
     * the call under PortalFetchPolicy::runFresh() to re-read the class list
     * from the portal as well.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $sessionToken, string $id, bool $fresh = false): ?array
    {
        return $this->catalog($sessionToken, $fresh)['by_id'][$id] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCourseId(string $sessionToken, string $courseId): ?array
    {
        return $this->catalog($sessionToken)['by_course_id'][$courseId] ?? null;
    }

    /**
     * @return array{by_id: array<string, array<string, mixed>>, by_course_id: array<string, array<string, mixed>>}
     */
    private function catalog(string $sessionToken, bool $fresh = false): array
    {
        return $this->dataCache->remember(
            $sessionToken,
            'class_catalog',
            [],
            function () use ($sessionToken): array {
                $catalog = ['by_id' => [], 'by_course_id' => []];
                $classesData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);

                foreach ($classesData['classes'] ?? [] as $class) {
                    $params = $class['join_link']['params'] ?? null;

                    if (! is_array($params)) {
                        continue;
                    }

                    if (isset($params['id'])) {
                        $catalog['by_id'][(string) $params['id']] = $params;
                    }

                    if (isset($params['course_id'])) {
                        $catalog['by_course_id'][(string) $params['course_id']] = $params;
                    }
                }

                return $catalog;
            },
            forceFresh: $fresh,
            cacheable: static fn (array $catalog): bool => $catalog['by_id'] !== [] || $catalog['by_course_id'] !== []
        )['data'];
    }
}
