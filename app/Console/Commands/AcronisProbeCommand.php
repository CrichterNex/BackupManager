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
    protected $signature = 'bms:acronis-probe {integration : Integration id} {--company= : Company id the integration belongs to}
        {--path= : Look at another endpoint instead of the device list (one of a few read-only paths)}
        {--query=* : Query parameter for --path, as key=value (repeatable)}
        {--values=* : For --path, print the distinct values of these dotted keys on each item, for example type or result.code}
        {--pair=* : For --path, print the distinct combinations of two dotted keys, as key1,key2, with machine names masked}';

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

    /** Shape of another endpoint's answer, plus the distinct values of keys that were asked for (kinds and states, never names). */
    private function probePath(Integration $integration): int
    {
        $query = [];
        foreach ($this->option('query') as $pair) {
            [$k, $v] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $query[$k] = $v;
        }
        try {
            $page = (new AcronisClient($integration))->probeGet((string) $this->option('path'), $query);
        } catch (IntegrationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $items = $page['items'] ?? (array_is_list($page) ? $page : []);
        $seen = [];
        $this->walk($page, '', $seen);
        ksort($seen);
        $this->info('Items returned: '.count($items));
        $this->line('Top-level keys: '.implode(', ', array_keys($page)));
        $this->newLine();
        $this->info('Field paths seen (count):');
        foreach ($seen as $k => $n) {
            $this->line("  $k  x$n");
        }
        foreach ($this->option('values') as $key) {
            $vals = [];
            foreach ($items as $it) {
                $v = $it;
                foreach (explode('.', (string) $key) as $part) {
                    $v = is_array($v) ? ($v[$part] ?? null) : null;
                }
                if (is_scalar($v)) {
                    $vals[(string) $v] = ($vals[(string) $v] ?? 0) + 1;
                }
            }
            arsort($vals);
            $this->newLine();
            $this->line("Values of $key: ".($vals ? implode(', ', array_map(fn ($v, $n) => "$v (x$n)", array_keys($vals), $vals)) : '(none)'));
        }

        foreach ($this->option('pair') as $pairSpec) {
            [$ka, $kb] = array_pad(explode(',', (string) $pairSpec, 2), 2, '');
            $get = function (array $it, string $key) {
                $v = $it;
                foreach (explode('.', $key) as $part) {
                    $v = is_array($v) ? ($v[$part] ?? null) : null;
                }

                return is_scalar($v) ? (string) $v : null;
            };
            $combos = [];
            foreach ($items as $it) {
                // Names of machines and plans are masked, so what is printed says what kind of activity it was and not whose.
                $mask = fn (?string $s) => $s === null ? null : str_ireplace(array_filter([$get($it, 'resource.name'), $get($it, 'context.MachineName'), $get($it, 'context.BackupPlanName'), $get($it, 'policy.name')]), '<name>', $s);
                $k = $mask($get($it, $ka)).'  =>  '.$mask($get($it, $kb));
                $combos[$k] = ($combos[$k] ?? 0) + 1;
            }
            arsort($combos);
            $this->newLine();
            $this->line("Combinations of $ka and $kb:");
            foreach ($combos as $c => $n) {
                $this->line("  $c  (x$n)");
            }
        }

        return self::SUCCESS;
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
            if ($this->option('path')) {
                return $this->probePath($integration);
            }
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
