<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Audit;
use App\Services\Reminders;
use App\Support\ApiException;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Mail reminders: when the daily digest goes out and to whom, what it would say now, and each person's own switch. */
class ReminderController extends Controller
{
    public function show(Request $request)
    {
        $id = (int) CompanyContext::id();
        $s = Reminders::settings($id);
        $me = $request->user();

        return response()->json(['success' => true, 'data' => [
            'settings' => $s->only(['enabled', 'send_hour', 'timezone', 'weekdays_only', 'include_managers']),
            'transport' => Reminders::transport(),
            'recipients' => Reminders::recipients($id, $s)->map(fn ($u) => ['username' => $u->username, 'role' => $u->role])->values(),
            'mine' => ['mail_reminders' => (bool) $me->mail_reminders, 'has_email' => (bool) $me->email],
            'recent' => Reminders::lastRuns($id),
        ]]);
    }

    public function update(Request $request)
    {
        $b = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'send_hour' => ['sometimes', 'integer', 'min:0', 'max:23'], 'timezone' => ['sometimes', 'string', 'max:64'],
            'weekdays_only' => ['sometimes', 'boolean'], 'include_managers' => ['sometimes', 'boolean'],
        ]);
        if (isset($b['timezone']) && ! in_array($b['timezone'], timezone_identifiers_list(), true)) {
            throw ApiException::badRequest('Unknown time zone. Use a name such as Africa/Johannesburg or UTC.');
        }
        $s = Reminders::settings((int) CompanyContext::id());
        DB::transaction(function () use ($s, $b, $request) {
            $s->forceFill($b)->save();
            Audit::log($request, 'reminder_settings_updated', 'company', $s->company_id, $b);
        });

        return $this->show($request);
    }

    /** Turn the digest on or off for the person asking. Only their own. */
    public function mine(Request $request)
    {
        $b = $request->validate(['enabled' => ['required', 'boolean']]);
        $request->user()->forceFill(['mail_reminders' => $b['enabled']])->save();

        return response()->json(['success' => true, 'data' => ['mail_reminders' => (bool) $b['enabled']]]);
    }

    /** Send the person asking the digest they would get today, right now, so they can see it and check that mail arrives. */
    public function sendNow(Request $request)
    {
        $me = $request->user();
        if (! $me->email) {
            throw ApiException::badRequest('Your account has no email address.');
        }
        try {
            $items = Reminders::sendTo($me, Company::findOrFail(CompanyContext::id()));
        } catch (\Throwable $e) {
            throw ApiException::badRequest('The mail could not be sent: '.mb_substr($e->getMessage(), 0, 200).' Check the mail settings in the .env file.');
        }

        return response()->json(['success' => true, 'data' => ['items' => $items] + Reminders::transport()]);
    }
}
