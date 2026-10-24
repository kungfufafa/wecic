<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PortalEndpointRegistry;
use Illuminate\Console\Command;

class PortalEndpointsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'portal:endpoints
                        {--key= : Tampilkan detail untuk endpoint tertentu}
                        {--category= : Filter endpoint berdasarkan kategori}';

    /**
     * The console command description.
     */
    protected $description = 'Melihat katalog endpoint portal (config/portal.php, bagian endpoints)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($endpointKey = $this->option('key')) {
            return $this->renderSingleEndpoint($endpointKey);
        }

        return $this->renderGroupedEndpoints($this->option('category'));
    }

    private function renderSingleEndpoint(string $key): int
    {
        try {
            $meta = PortalEndpointRegistry::describe($key);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Endpoint: {$key}");
        $this->line(sprintf('  Method     : %s', $meta['method']));
        $this->line(sprintf('  URL        : %s', $meta['url']));
        $this->line(sprintf('  Category   : %s', $meta['category'] ?? '-'));
        $this->line(sprintf('  Priority   : %s', $meta['priority'] ?? '-'));
        $this->line(sprintf('  Requires session : %s', $meta['requires_session'] ? 'yes' : 'no'));
        $this->line(sprintf('  Description: %s', $meta['description']));

        if ($meta['required_params'] !== []) {
            $this->line('  Required Params : '.implode(', ', $meta['required_params']));
        }

        return self::SUCCESS;
    }

    private function renderGroupedEndpoints(?string $category = null): int
    {
        $grouped = PortalEndpointRegistry::groupedByCategory();

        if ($category !== null) {
            $grouped = array_filter($grouped, static fn ($_, $key) => $key === $category, ARRAY_FILTER_USE_BOTH);

            if ($grouped === []) {
                $this->warn("Kategori '{$category}' tidak ditemukan.");

                return self::FAILURE;
            }
        }

        foreach ($grouped as $group => $endpoints) {
            $this->info(strtoupper($group));
            $rows = [];

            foreach ($endpoints as $key => $definition) {
                $rows[] = [
                    'key' => $key,
                    'method' => strtoupper($definition['method']),
                    'priority' => $definition['priority'] ?? '-',
                    'requires_session' => ($definition['requires_session'] ?? true) ? 'yes' : 'no',
                    'description' => $definition['description'] ?? '-',
                ];
            }

            $this->table(
                ['Key', 'Method', 'Priority', 'Session?', 'Description'],
                $rows
            );
        }

        $this->info('Gunakan --key=<nama> untuk melihat detail endpoint tertentu.');

        return self::SUCCESS;
    }
}
