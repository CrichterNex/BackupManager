<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Reminders;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'bms:send-reminders {--company= : Only this company id}';

    protected $description = 'Send the daily reminder mails that are due now (the engine does this every minute; this is for trying it by hand)';

    public function handle(): int
    {
        $sent = 0;
        foreach (Company::where('is_active', true)->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))->pluck('id') as $id) {
            $sent += Reminders::sendDue($id);
        }
        $t = Reminders::transport();
        $this->info("Sent $sent reminder mail(s) using the '{$t['mailer']}' mailer".($t['delivers'] ? '.' : ', which writes them to the log and delivers nothing.'));

        return self::SUCCESS;
    }
}
