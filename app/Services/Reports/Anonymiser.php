<?php

namespace App\Services\Reports;

/**
 * Takes the names out of a report so it can go to someone outside the company. A server is shown by what it stores, never by its name; a person
 * who did a test is "Technician 1", "Technician 2"; the company is withheld; the paths and items a backup covers are withheld, because they name
 * folders, mailboxes and sites. Dates, counts and results stay, since they are the report.
 *
 * The same server always gets the same label inside one report, and rows are put in order of the label, so the order of the report does not
 * give the server names away (they are listed alphabetically otherwise).
 */
final class Anonymiser
{
    public const COMPANY = 'The organisation (name withheld)';

    public const WITHHELD = 'Withheld';

    /** @var array<string, string> hostname => label */
    private array $servers = [];

    /** @var array<string, string> person => label */
    private array $people = [];

    /** @param  list<array<string, mixed>>  $rows  rows with 'hostname' and 'function' */
    public function __construct(array $rows)
    {
        $entries = [];
        foreach ($rows as $r) {
            $entries[$r['hostname']] = $r['function'] ?? null;
        }
        // Order by what the server does, then by name only to settle a tie, so the same input always gives the same labels.
        uksort($entries, fn ($a, $b) => [mb_strtolower((string) ($entries[$a] ?? '~')), $a] <=> [mb_strtolower((string) ($entries[$b] ?? '~')), $b]);
        $count = [];
        foreach ($entries as $fn) {
            if ($fn !== null && trim($fn) !== '') {
                $count[mb_strtolower($fn)] = ($count[mb_strtolower($fn)] ?? 0) + 1;
            }
        }
        // A function that several servers share is numbered on every one of them, so none stands out as the plain one.
        $seen = [];
        $blank = 0;
        foreach ($entries as $host => $fn) {
            if ($fn === null || trim($fn) === '') {
                $this->servers[$host] = 'Server '.(++$blank).' (function not stated)';

                continue;
            }
            $k = mb_strtolower($fn);
            $seen[$k] = ($seen[$k] ?? 0) + 1;
            $this->servers[$host] = $count[$k] > 1 ? "$fn ({$seen[$k]})" : $fn;
        }
        foreach ($rows as $r) {
            $by = $r['last_test']['by'] ?? $r['last_test_by'] ?? null;
            if ($by) {
                $this->people[$by] = '';
            }
        }
        ksort($this->people);
        $i = 0;
        foreach (array_keys($this->people) as $p) {
            $this->people[$p] = 'Technician '.(++$i);
        }
    }

    public function server(string $hostname): string
    {
        return $this->servers[$hostname] ?? 'Server (not listed)';
    }

    public function person(?string $name): string
    {
        return $name === null || $name === '' ? 'Not recorded' : ($this->people[$name] ?? 'Technician');
    }

    /** Rows in the order of their anonymised label. @param  list<array<string, mixed>>  $rows */
    public function sorted(array $rows): array
    {
        usort($rows, fn ($a, $b) => strnatcasecmp($this->server($a['hostname']), $this->server($b['hostname'])));

        return $rows;
    }
}
