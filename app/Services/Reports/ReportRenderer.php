<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/** Server-rendered, print-ready HTML for a stored report snapshot. Every value is escaped. */
final class ReportRenderer
{
    private const TITLES = [
        'compliance' => 'Backup Compliance Report',
        'sla' => 'SLA Performance Report',
        'security' => 'Security and Access Report',
        'dr_readiness' => 'Disaster Recovery Readiness Report',
    ];

    private const LEVELS = [
        'compliant' => ['Compliant', 'good'],
        'compliant_with_notes' => ['Compliant with notes', 'warn'],
        'non_compliant' => ['Non-compliant', 'bad'],
        'insufficient_data' => ['Insufficient data', 'muted'],
    ];

    private static function esc(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function label(mixed $s): string
    {
        return self::esc(ucfirst(str_replace('_', ' ', (string) ($s ?? ''))));
    }

    private static function date(mixed $v): string
    {
        return $v ? self::esc(CarbonImmutable::parse($v, 'UTC')->format('Y-m-d H:i').' UTC') : 'Never';
    }

    private static function day(mixed $v): string
    {
        return self::esc(CarbonImmutable::parse($v, 'UTC')->format('Y-m-d'));
    }

    private static function val(mixed $v, string $suffix = ''): string
    {
        return $v === null ? '<span class="muted">No data</span>' : self::esc($v).$suffix;
    }

    private static function dur(?int $s): string
    {
        if ($s === null) {
            return '<span class="muted">No data</span>';
        }

        return $s >= 3600 ? intdiv($s, 3600).' h '.(int) round(($s % 3600) / 60).' min' : (int) round($s / 60).' min';
    }

    private static function table(array $heads, array $rows, string $empty = 'Nothing to show for this period.'): string
    {
        if (! $rows) {
            return '<p class="muted">'.self::esc($empty).'</p>';
        }
        $th = implode('', array_map(fn ($h) => '<th>'.self::esc($h).'</th>', $heads));
        $tr = implode('', array_map(fn ($r) => '<tr>'.implode('', array_map(fn ($c) => "<td>$c</td>", $r)).'</tr>', $rows));

        return "<table><thead><tr>$th</tr></thead><tbody>$tr</tbody></table>";
    }

    private static function kv(array $rows): string
    {
        return '<dl>'.implode('', array_map(fn ($r) => '<div><dt>'.self::esc($r[0]).'</dt><dd>'.$r[1].'</dd></div>', $rows)).'</dl>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section><h2>'.self::esc($title).'</h2>'.$body.'</section>';
    }

    private static function badge(string $text, string $tone): string
    {
        return '<span class="badge '.$tone.'">'.self::esc($text).'</span>';
    }

    private static function notMeasured(array $items): string
    {
        return self::section('Not measured by BMS', '<p class="muted">These items appear in typical compliance reports but BMS has no data for them, so they are not stated here.</p><ul>'
            .implode('', array_map(fn ($i) => '<li>'.self::esc($i).'</li>', $items)).'</ul>');
    }

    private static function signature(string $company): string
    {
        return self::section('Sign-off', '<p class="small">The figures above are calculated from records held in BMS for '.self::esc($company).'. This report is not an independent audit.</p>'
            .'<div class="sign"><div>Prepared by<br><br>__________________________</div><div>Name and title<br><br>__________________________</div><div>Date<br><br>______________</div></div>');
    }

    private static function compliance(array $s): string
    {
        [$levelText, $tone] = self::LEVELS[$s['certification']['level']];
        $t = $s['tests'];
        $b = $s['backups'];
        $f = $s['failures'];

        return implode('', [
            self::section('Summary', '<p class="status '.$tone.'">'.self::esc($levelText).'</p><ul>'.implode('', array_map(fn ($r) => '<li>'.self::esc($r).'</li>', $s['certification']['reasons'])).'</ul>'
                .self::kv([['Active servers', self::esc($s['servers']['total'])], ['Backups finished', self::esc($b['finished'])], ['Tests run', self::esc($t['run'])], ['Unresolved failures', self::esc(array_sum($f['open_now']))]])),
            self::section('Backup health', self::kv([
                ['Success rate', self::val($b['success_rate_pct'], '%').' ('.self::esc($b['success']).' of '.self::esc($b['finished']).')'],
                ['Succeeded with warnings', self::esc($b['warning'])], ['Failed', self::esc($b['failed'])],
                ['Average duration', self::dur($b['avg_duration_seconds'])], ['Average size', self::val($b['avg_size_gb'], ' GB')],
            ])),
            self::section('Test and verification evidence', self::kv([
                ['Test coverage', self::val($t['coverage_pct'], '%').' ('.self::esc($t['servers_tested']).' of '.self::esc($t['servers_active']).' servers tested)'],
                ['Tests run', self::esc($t['run'])], ['Passed', self::esc($t['passed'])], ['Failed', self::esc($t['failed'])], ['Skipped (not run)', self::esc($t['skipped'])],
                ['Pass rate', self::val($t['pass_rate_pct'], '%')],
                ['Hash-verified', self::esc($t['hash_checked']).' tests, '.self::esc($t['hash_mismatches']).' mismatch(es)'],
                ['Verification method', self::esc($s['verification_method'])],
            ]).self::table(['Test type', 'Run', 'Passed'], array_map(fn ($x) => [self::label($x['test_type']), self::esc($x['run']), self::esc($x['passed'])], $t['by_type']), 'No tests completed in this period.')),
            self::section('Failures and resolution', self::kv([['Raised in period', self::esc($f['raised'])], ['Resolved', self::esc($f['resolved'])], ['Mean time to resolve', self::val($f['mttr_hours'], ' hours')]])
                .self::table(['Resolution', 'Count'], array_map(fn ($x) => [self::label($x['resolution_type']), self::esc($x['n'])], $f['resolution_types']), 'No failures were resolved in this period.')),
            self::section('Retention and records', self::kv([
                ['Servers with a retention policy', self::esc($s['servers']['with_retention']).' of '.self::esc($s['servers']['total'])],
                ['Servers with a backup location recorded', self::esc($s['servers']['with_location']).' of '.self::esc($s['servers']['total'])],
                ['Audit trail entries in period', self::esc($s['audit_entries'])],
            ])),
            self::notMeasured($s['not_measured']),
        ]);
    }

    private static function sla(array $s): string
    {
        $tone = fn (string $st) => $st === 'met' ? 'good' : ($st === 'missed' ? 'bad' : 'muted');
        $names = ['met' => 'Met', 'missed' => 'Missed', 'no_data' => 'No data'];
        $inc = $s['incidents'];

        return implode('', [
            self::section('Service levels against targets', self::table(['Metric', 'Target', 'Actual', 'Status'],
                array_map(fn ($m) => [self::esc($m['metric']), self::esc($m['target_pct']).'%', self::val($m['actual_pct'], '%'), self::badge($names[$m['status']], $tone($m['status']))], $s['metrics']))
                .'<p>'.self::esc($s['summary']['met']).' of '.self::esc($s['summary']['measurable']).' measurable targets met.</p>'),
            self::section('Backup performance', self::kv([['Backups finished', self::esc($s['backups']['finished'])], ['Failed', self::esc($s['backups']['failed'])], ['Average duration', self::dur($s['backups']['avg_duration_seconds'])], ['95th percentile duration', self::dur($s['backups']['p95_duration_seconds'])]])),
            self::section('Testing', self::kv([['Tests run', self::esc($s['tests']['run'])], ['Passed', self::esc($s['tests']['passed'])], ['Failed', self::esc($s['tests']['failed'])], ['Skipped', self::esc($s['tests']['skipped'])], ['Servers tested', self::esc($s['tests']['servers_tested']).' of '.self::esc($s['tests']['servers_active'])]])),
            self::section('Incidents', self::table(['Severity', 'Raised', 'Resolved', 'Mean time to resolve'],
                array_map(fn ($x) => [self::label($x['severity']), self::esc($x['raised']), self::esc($x['resolved']), self::val($x['mttr_hours'], ' hours')], $inc['by_severity']))
                .'<p>Unresolved now: '.self::esc($inc['open_now']['critical']).' critical, '.self::esc($inc['open_now']['high']).' high, '.self::esc($inc['open_now']['medium']).' medium, '.self::esc($inc['open_now']['low']).' low.</p>'),
            self::notMeasured($s['not_measured']),
        ]);
    }

    private static function security(array $s): string
    {
        return implode('', [
            self::section('Users and access', self::table(['Role', 'Sign-in method', 'Active', 'Disabled'],
                array_map(fn ($u) => [self::label(str_replace('company_', '', $u['role'])), self::esc($u['auth_method']), self::esc($u['active']), self::esc($u['disabled'])], $s['users']['by_role']), 'No users.')
                .self::kv([['Active admins', self::esc($s['users']['active_admins'])], ['Active users with no login in 90 days', self::esc($s['users']['active_without_recent_login'])]])),
            self::section('Activity in period', self::kv([['Successful sign-ins', self::esc($s['sign_ins']['successful'])], ['Failed sign-ins (known user)', self::esc($s['sign_ins']['failed_attributed_to_a_user'])], ['Password changes', self::esc($s['password_changes'])], ['Audit entries', self::esc($s['audit']['entries'])]])
                .self::table(['Action', 'Count', 'Failed'], array_map(fn ($a) => [self::label($a['action']), self::esc($a['total']), self::esc($a['failed'])], $s['audit']['by_action']), 'No audited activity in this period.')),
            self::section('Built-in controls', self::table(['Control', 'Detail'], array_map(fn ($c) => [self::esc($c['control']), self::esc($c['detail'])], $s['controls']))),
            self::notMeasured($s['not_measured']),
        ]);
    }

    private static function dr(array $s): string
    {
        [$text, $tone] = match ($s['summary']['status']) {
            'ready' => ['Ready', 'good'], 'no_servers' => ['No servers', 'muted'], default => ['Not ready', 'bad'],
        };

        return implode('', [
            self::section('Summary', '<p class="status '.$tone.'">'.self::esc($text).'</p>'.self::kv([
                ['Servers ready', self::esc($s['summary']['ready']).' of '.self::esc($s['summary']['total'])],
                ['Rule', 'Latest backup succeeded within '.self::esc($s['params']['max_backup_age_hours']).' hours, latest test passed within '.self::esc($s['params']['max_test_age_days']).' days, and no unresolved critical or high failures.'],
                ['Last full recovery test', $s['last_full_recovery_test'] ? self::date($s['last_full_recovery_test']) : '<span class="muted">None recorded</span>'],
            ])),
            self::section('Server readiness', self::table(['Server', 'OS', 'Last backup', 'Last test', 'Ready', 'Why not'],
                array_map(fn ($v) => [
                    self::esc($v['hostname']), self::esc($v['os_type']),
                    self::date($v['last_backup_time']).($v['last_backup_status'] ? ' ('.self::esc($v['last_backup_status']).')' : ''),
                    self::date($v['last_test_time']).($v['last_test_status'] ? ' ('.self::esc($v['last_test_status']).')' : ''),
                    self::badge($v['ready'] ? 'Yes' : 'No', $v['ready'] ? 'good' : 'bad'), self::esc(implode('; ', $v['reasons'])),
                ], $s['servers']), 'No active servers.')),
            self::notMeasured($s['not_measured']),
        ]);
    }

    private const CSS = <<<'CSS'
:root{--ink:#1f2933;--muted:#6b7785;--line:#d9dee4;--accent:#185fa5;--good:#0f6e56;--warn:#9a6700;--bad:#b42318}
*{box-sizing:border-box}body{margin:0;background:#eef1f4;color:var(--ink);font:14px/1.55 system-ui,'Segoe UI',Arial,sans-serif}
.page{max-width:900px;margin:24px auto;background:#fff;padding:48px;box-shadow:0 1px 8px rgba(0,0,0,.1)}
header{border-bottom:3px solid var(--accent);padding-bottom:16px;margin-bottom:24px}
h1{margin:0 0 4px;font-size:26px;color:var(--accent)}h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--accent);border-bottom:1px solid var(--line);padding-bottom:6px;margin:28px 0 12px}
.sub{color:var(--muted)}.muted{color:var(--muted)}.small{font-size:12px}
table{width:100%;border-collapse:collapse;margin:8px 0}th{text-align:left;background:#f4f6f8;border-bottom:2px solid var(--line)}th,td{padding:7px 9px;border-bottom:1px solid var(--line);vertical-align:top}
dl{margin:8px 0}dl div{display:flex;gap:12px;padding:4px 0;border-bottom:1px dotted var(--line)}dt{width:260px;color:var(--muted);flex:none}dd{margin:0}
.status{display:inline-block;font-size:18px;font-weight:700;padding:6px 16px;border-radius:999px;border:2px solid currentColor}
.good{color:var(--good)}.warn{color:var(--warn)}.bad{color:var(--bad)}
.badge{display:inline-block;padding:1px 9px;border-radius:999px;border:1px solid currentColor;font-size:12px;font-weight:600}
.sign{display:flex;gap:32px;margin-top:28px}.sign div{flex:1}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;padding:0;max-width:none}h2{break-after:avoid}table,section{break-inside:avoid}}
CSS;

    public static function render(array $snap, int $reportId): string
    {
        $type = $snap['type'];
        $body = match ($type) {
            'compliance' => self::compliance($snap), 'sla' => self::sla($snap), 'security' => self::security($snap), default => self::dr($snap),
        };
        $period = $type === 'dr_readiness'
            ? 'As at '.self::date($snap['generated_at'])
            : self::day($snap['period']['from']).' to '.self::day(CarbonImmutable::parse($snap['period']['to'], 'UTC')->subSecond());
        $company = $snap['company']['name'];

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.self::esc(self::TITLES[$type]).' - '.self::esc($company).'</title><style>'.self::CSS.'</style></head><body><div class="page">'
            .'<header><h1>'.self::esc(self::TITLES[$type]).'</h1><div>'.self::esc($company).'</div><div class="sub">'.$period.' · Report '.self::esc($reportId).' · Generated '.self::date($snap['generated_at']).'</div></header>'
            .$body.self::signature($company).'</div></body></html>';
    }
}
