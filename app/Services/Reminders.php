<?php

namespace App\Services;

use App\Models\BackupFailure;
use App\Models\BackupTestExecution;
use App\Models\BackupTestFailure;
use App\Models\Company;
use App\Models\Integration;
use App\Models\ReminderLog;
use App\Models\ReminderSetting;
use App\Models\Server;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Constants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A daily mail to the people who have things to fix or do: backup problems and test failures nobody has dealt with, tests waiting to be done, and
 * servers that have not been tested for a month. Admins get all of it. Managers get it too if the company asks. A technician gets only the tests
 * waiting for them. Nothing is sent when there is nothing to say, a person gets at most one a day, and anyone can turn theirs off.
 *
 * BMS only says what is outstanding. It does not nag about things that are fine, and the mail names servers but never includes a log, a path or a
 * credential.
 */
final class Reminders
{
    /** Longest list shown for each kind of thing; the rest is counted. */
    private const SHOW = 12;

    /** A server is called untested when its last test is older than this. */
    private const UNTESTED_DAYS = 30;

    public static function settings(int $companyId): ReminderSetting
    {
        return CompanyContext::run($companyId, function () use ($companyId) {
            $s = ReminderSetting::firstOrCreate(['company_id' => $companyId], ['enabled' => true]);

            // A row just made holds only what was given; reading it back brings in the defaults (the hour, the zone) the database filled in.
            return $s->wasRecentlyCreated ? $s->refresh() : $s;
        });
    }

    /** Who a company's digest goes to: active people with an address who have not turned it off, in the roles it is meant for. @return \Illuminate\Support\Collection<int, User> */
    public static function recipients(int $companyId, ReminderSetting $s)
    {
        $roles = ['company_admin', 'company_technician', ...($s->include_managers ? ['company_manager'] : [])];

        return User::where('company_id', $companyId)->where('active', true)->where('mail_reminders', true)->whereNotNull('email')->where('email', '!=', '')->whereIn('role', $roles)->orderBy('username')->get();
    }

    /** @return array<string, mixed> what this person has to fix or do, with a total */
    public static function digest(User $user): array
    {
        $technician = $user->role === 'company_technician';
        $age = fn ($t) => $t ? max(0, (int) CarbonImmutable::parse($t, 'UTC')->diffInHours(now(), false)) : null;
        $take = fn ($rows) => ['total' => count($rows), 'rows' => array_slice($rows, 0, self::SHOW)];
        $d = ['backup_problems' => $take([]), 'test_failures' => $take([]), 'tests_waiting' => $take([]), 'to_verify' => $take([]), 'untested' => $take([]), 'connections' => $take([])];

        if (! $technician) {
            $d['backup_problems'] = $take(BackupFailure::join('servers as s', 's.id', '=', 'backup_failures.server_id')->where('backup_failures.resolved', false)
                ->orderByRaw("FIELD(backup_failures.severity, 'high', 'medium', 'low')")->orderBy('backup_failures.first_seen_at')
                ->get(['s.hostname', 'backup_failures.kind', 'backup_failures.severity', 'backup_failures.first_seen_at', 'backup_failures.missed_runs', 'backup_failures.active'])
                ->map(fn ($f) => ['server' => $f->hostname, 'what' => ucfirst($f->kind).($f->active ? '' : ' (recovered, not yet reviewed)').($f->missed_runs ? ", {$f->missed_runs} backup".($f->missed_runs === 1 ? '' : 's').' missed' : ''),
                    'severity' => $f->severity, 'hours' => $age($f->first_seen_at)])->all());
            $d['test_failures'] = $take(BackupTestFailure::join('backup_test_executions as t', 't.id', '=', 'backup_test_failures.test_execution_id')->join('backup_jobs as j', 'j.id', '=', 't.backup_job_id')->join('servers as s', 's.id', '=', 'j.server_id')
                ->where('backup_test_failures.resolved', false)->orderByRaw("FIELD(backup_test_failures.severity, 'critical', 'high', 'medium', 'low')")->orderBy('backup_test_failures.created_at')
                ->get(['s.hostname', 't.test_type', 'backup_test_failures.severity', 'backup_test_failures.failure_type', 'backup_test_failures.created_at'])
                ->map(fn ($f) => ['server' => $f->hostname, 'what' => (Constants::TEST_HELP[$f->test_type]['title'] ?? $f->test_type).' failed ('.str_replace('_', ' ', $f->failure_type).')', 'severity' => $f->severity, 'hours' => $age($f->created_at)])->all());
            $d['to_verify'] = $take(BackupTestExecution::join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id')->join('servers as s', 's.id', '=', 'j.server_id')
                ->where('backup_test_executions.verify_state', 'pending')->orderBy('backup_test_executions.completed_time')
                ->get(['s.hostname', 'backup_test_executions.test_type', 'backup_test_executions.completed_time'])
                ->map(fn ($t) => ['server' => $t->hostname, 'what' => (Constants::TEST_HELP[$t->test_type]['title'] ?? $t->test_type).' waiting for a second person to check', 'hours' => $age($t->completed_time)])->all());
            $d['untested'] = $take(Server::where('active', true)->where(fn ($q) => $q->whereNull('last_test_time')->orWhere('last_test_time', '<', now()->subDays(self::UNTESTED_DAYS)))->orderBy('last_test_time')->orderBy('hostname')
                ->get(['hostname', 'last_test_time'])->map(fn ($s) => ['server' => $s->hostname, 'what' => $s->last_test_time ? 'Last tested '.CarbonImmutable::parse($s->last_test_time, 'UTC')->format('Y-m-d') : 'Never tested', 'hours' => $age($s->last_test_time)])->all());
            $d['connections'] = $take(Integration::where('active', true)->where('last_sync_status', 'error')->orderBy('name')->get(['name', 'last_sync_error'])
                ->map(fn ($i) => ['server' => $i->name, 'what' => 'Not syncing: '.mb_substr((string) $i->last_sync_error, 0, 140)])->all());
        }

        // Tests a person has to do by hand: for a technician, the ones assigned to them or waiting on their servers; for an admin, all of them.
        $q = BackupTestExecution::join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id')->join('servers as s', 's.id', '=', 'j.server_id')
            ->where('backup_test_executions.manual', true)->whereIn('backup_test_executions.test_status', ['pending', 'running']);
        if ($technician) {
            $q->where(fn ($w) => $w->where('backup_test_executions.assigned_to', $user->id)->orWhere(fn ($x) => $x->whereNull('backup_test_executions.assigned_to')
                ->whereIn('s.id', fn ($a) => $a->select('server_id')->from('server_assignments')->where('user_id', $user->id))));
        }
        $d['tests_waiting'] = $take($q->orderBy('backup_test_executions.created_at')->get(['s.hostname', 'backup_test_executions.test_type', 'backup_test_executions.created_at', 'backup_test_executions.assigned_to', 'backup_test_executions.test_status'])
            ->map(fn ($t) => ['server' => $t->hostname, 'what' => (Constants::TEST_HELP[$t->test_type]['title'] ?? $t->test_type).($t->test_status === 'running' ? ' in progress' : ($t->assigned_to ? ' to do' : ' not yet picked up')), 'hours' => $age($t->created_at)])->all());

        $d['total'] = array_sum(array_map(fn ($k) => $d[$k]['total'], ['backup_problems', 'test_failures', 'tests_waiting', 'to_verify', 'untested', 'connections']));

        return $d;
    }

    private const TITLES = [
        'backup_problems' => ['Backup problems to review', 'backups'], 'test_failures' => ['Test failures to deal with', 'tests'], 'tests_waiting' => ['Tests to do by hand', 'tests'],
        'to_verify' => ['Tests waiting for a second person', 'tests'], 'untested' => ['Servers not tested in '.self::UNTESTED_DAYS.' days', 'tests'], 'connections' => ['Backup platform connections not syncing', 'integrations'],
    ];

    private static function ago(?int $hours): string
    {
        return match (true) {
            $hours === null => '',
            $hours < 48 => " ({$hours} h)",
            default => ' ('.intdiv($hours, 24).' days)',
        };
    }

    /** @return array{subject: string, html: string, text: string} */
    public static function render(array $d, User $user, Company $company): array
    {
        $base = rtrim((string) config('app.url'), '/').'/companies/'.$company->slug;
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->username;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $bits = [];
        foreach (['backup_problems' => 'backup problem', 'test_failures' => 'test failure', 'tests_waiting' => 'test to do'] as $k => $w) {
            $d[$k]['total'] > 0 && $bits[] = $d[$k]['total'].' '.$w.($d[$k]['total'] === 1 ? '' : 's');
        }
        $subject = $d['total'] === 0 ? 'BMS: nothing needs attention' : 'BMS: '.($bits ? implode(', ', $bits) : $d['total'].' thing'.($d['total'] === 1 ? '' : 's').' to look at').' for '.$company->name;

        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1f2933;max-width:640px"><p>Hello '.$e($name).',</p>'
            .($d['total'] === 0 ? '<p>Nothing needs attention right now.</p>' : '<p>These are still waiting for someone at '.$e($company->name).'.</p>');
        $text = "Hello $name,\n\n".($d['total'] === 0 ? "Nothing needs attention right now.\n" : "These are still waiting for someone at {$company->name}.\n");
        foreach (self::TITLES as $k => [$title, $page]) {
            if ($d[$k]['total'] === 0) {
                continue;
            }
            $html .= '<h3 style="margin:18px 0 6px;font-size:15px">'.$e($title).' ('.$d[$k]['total'].')</h3><ul style="margin:0;padding-left:18px">';
            $text .= "\n$title (".$d[$k]['total'].")\n";
            foreach ($d[$k]['rows'] as $r) {
                $line = $r['server'].': '.$r['what'].self::ago($r['hours'] ?? null);
                $html .= '<li>'.($r['severity'] ?? null ? '<strong>'.$e(ucfirst($r['severity'])).'</strong> ' : '').$e($line).'</li>';
                $text .= '  - '.(($r['severity'] ?? null) ? ucfirst($r['severity']).' ' : '').$line."\n";
            }
            $more = $d[$k]['total'] - count($d[$k]['rows']);
            $more > 0 && ($html .= '<li>and '.$more.' more</li>') && ($text .= "  - and $more more\n");
            $html .= '</ul><p style="margin:6px 0 0"><a href="'.$e("$base/$page").'">Open '.$e(ucfirst($page)).' in BMS</a></p>';
            $text .= "  Open: $base/$page\n";
        }
        $html .= '<p style="color:#6b7785;font-size:12px;margin-top:24px">You get this once a day while something is outstanding. To stop it, turn off mail reminders under My profile in BMS.</p></div>';
        $text .= "\nYou get this once a day while something is outstanding. To stop it, turn off mail reminders under My profile in BMS.\n";

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** Send one person their digest now. Returns the number of things in it. @throws \Throwable when the mail cannot be sent */
    public static function sendTo(User $user, Company $company, ?array $digest = null): int
    {
        $d = $digest ?? self::digest($user);
        $m = self::render($d, $user, $company);
        Mail::send([], [], function ($msg) use ($user, $m) {
            $msg->to($user->email)->subject($m['subject'])->html($m['html'])->text($m['text']);
        });

        return $d['total'];
    }

    /**
     * Send the day's digest to everyone due one, for a company. Run every minute by the engine: it acts only in the hour the company chose, and
     * only once for each person that day, so the rest of the hour does nothing. Returns how many mails were sent.
     */
    public static function sendDue(int $companyId, ?CarbonImmutable $now = null): int
    {
        $company = Company::find($companyId);
        $s = self::settings($companyId);
        if (! $company || ! $s->enabled) {
            return 0;
        }
        $local = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($s->timezone);
        if ($local->hour !== $s->send_hour || ($s->weekdays_only && $local->isWeekend())) {
            return 0;
        }
        $sent = 0;
        CompanyContext::run($companyId, function () use ($companyId, $s, $company, $local, &$sent) {
            foreach (self::recipients($companyId, $s) as $user) {
                if (ReminderLog::where('user_id', $user->id)->where('for_date', $local->toDateString())->exists()) {
                    continue;
                }
                $d = self::digest($user);
                $row = ['company_id' => $companyId, 'user_id' => $user->id, 'for_date' => $local->toDateString(), 'items' => $d['total']];
                if ($d['total'] === 0) {
                    ReminderLog::create($row + ['status' => 'nothing']);

                    continue;
                }
                try {
                    self::sendTo($user, $company, $d);
                    ReminderLog::create($row + ['status' => 'sent']);
                    $sent++;
                } catch (\Throwable $e) {
                    // Not tried again today: a mail server that is down would otherwise be hit every minute.
                    Log::warning("Reminder mail to user {$user->id} failed: ".$e->getMessage());
                    ReminderLog::create($row + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);
                }
            }
        });

        return $sent;
    }

    /** How mail leaves this installation, for the settings page to say so plainly. @return array{mailer: string, delivers: bool} */
    public static function transport(): array
    {
        $m = (string) config('mail.default');

        return ['mailer' => $m, 'delivers' => ! in_array($m, ['log', 'array'], true)];
    }

    public static function lastRuns(int $companyId, int $limit = 15)
    {
        return DB::table('reminder_log as l')->join('users as u', 'u.id', '=', 'l.user_id')->where('l.company_id', $companyId)->orderByDesc('l.sent_at')->orderByDesc('l.id')->limit($limit)
            ->get(['u.username', 'l.for_date', 'l.status', 'l.items', 'l.error', 'l.sent_at']);
    }
}
