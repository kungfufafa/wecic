<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Extractors\ClassesExtractor;

/**
 * Lookup of the upstream class link parameters (`id`, `dosen_id`, `course_id`)
 * by class id or course id, built from the LMS class list.
 */
class PortalClassCatalog
{
    public function __construct(
        private readonly ClassesExtractor $classesExtractor,
        private readonly PortalDataCache $dataCache
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $sessionToken, string $id): ?array
    {
        return $this->catalog($sessionToken)['by_id'][$id] ?? null;
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
    private function catalog(string $sessionToken): array
    {
        return $this->dataCache->remember(
            $sessionToken,
            'class_catalog',
            [],
            function () use ($sessionToken): array {
                $catalog = ['by_id' => [], 'by_course_id' => []];
                $classesData = $this->classesExtractor->extractClassesData($sessionToken);

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
            cacheable: static fn (array $catalog): bool => $catalog['by_id'] !== [] || $catalog['by_course_id'] !== []
        )['data'];
    }
}
