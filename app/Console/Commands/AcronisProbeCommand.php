<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Integrations\AcronisClient;
use App\Services\Integrations\IntegrationException;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

/**
 * Read-only check of what Acronis actually returns, to confirm BMS reads it correctly. Prints the SHAPE of the
 * response (field paths, types, the set of status words and policy types), never device names or other values.
 */
class AcronisProbeCommand extends Command
{
    protected $signature = 'bms:acronis-probe {integration : Integration id} {--company= : Company id the integration belongs to}';

    protected $description = 'Show the structure of the first page Acronis returns for a connection (read-only, no names or secrets)';

    private function walk(mixed $v, string $path, array &$seen): void
    {
        if (is_array($v) && array_is_list($v)) {
            $seen[$path.'[]'] = ($seen[$path.'[]'] ?? 0) + 1;
            foreach ($v as $item) {
                $this->walk($item, $path.'[]', $seen);
            }

            return;
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $this->walk($x, $path === '' ? (string) $k : "$path.$k", $seen);
            }

            return;
        }
        $type = get_debug_type($v);
        if (is_string($v) && preg_match('/^\d{4}-\d\d-\d\dT/', $v)) {
            $type = 'string (ISO 8601 time)';
        } elseif (is_int($v) && $v > 1_000_000_000 && $v < 5_000_000_000) {
            $type = 'int (looks like a Unix time)';
        }
        $key = "$path: $type";
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    }

    public function handle(): int
    {
        $companyId = (int) ($this->option('company') ?: Integration::withoutGlobalScopes()->whereKey($this->argument('integration'))->value('company_id'));
        $integration = Integration::withoutGlobalScopes()->where('company_id', $companyId)->find($this->argument('integration'));
        if (! $integration) {
            $this->error('No such integration.');

            return self::FAILURE;
        }

        return CompanyContext::run($companyId, function () use ($integration) {
            try {
                $page = (new AcronisClient($integration))->rawFirstPage();
            } catch (IntegrationException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $seen = [];
            $this->walk($page, '', $seen);
            ksort($seen);
            $this->info('Items on first page: '.count($page['items'] ?? []));
            $this->line('Top-level keys: '.implode(', ', array_keys($page)));
            $this->line('Paging: '.json_encode(array_map(fn ($x) => is_scalar($x) ? get_debug_type($x) : array_map('get_debug_type', (array) $x), $page['paging'] ?? [])));
            $this->newLine();
            $this->info('Field paths seen (count):');
            foreach ($seen as $k => $n) {
                $this->line("  $k  x$n");
            }
            $status = $types = [];
            foreach ($page['items'] ?? [] as $it) {
                $status[] = $it['aggregate']['status'] ?? '(none)';
                foreach ($it['policies'] ?? [] as $p) {
                    $types[] = $p['type'] ?? '(none)';
                }
            }
            $this->newLine();
            $this->line('aggregate.status values: '.implode(', ', array_unique($status)));
            $this->line('policies[].type values:  '.implode(', ', array_unique($types)));
            $devices = (new AcronisClient($integration))->listMachines();
            $with = count(array_filter($devices, fn ($d) => $d['last_success_at'] !== null));
            $this->newLine();
            $this->line(count($devices)." machine(s) parsed, $with with a backup success time BMS can read.");

            return self::SUCCESS;
        });
    }
}
