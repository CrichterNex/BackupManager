<?php

namespace App\Console\Commands;

use App\Services\Engine\Engine;
use Illuminate\Console\Command;

class EngineCommand extends Command
{
    protected $signature = 'bms:engine';

    protected $description = 'Run one pass of the test engine: queue due tests, run them, escalate stale failures, archive scheduled reports';

    public function handle(): int
    {
        $r = Engine::tick();
        if ($r === null) {
            $this->line('Another engine pass is already running.');

            return self::SUCCESS;
        }
        if ($r['queued'] || $r['ran'] || $r['escalated'] || $r['reports'] || $r['expired'] || $r['backups']) {
            $this->info("Engine: queued {$r['queued']}, ran {$r['ran']}, escalated {$r['escalated']}, reports {$r['reports']}, agent backups queued {$r['backups']}, agent commands expired {$r['expired']}");
        }

        return self::SUCCESS;
    }
}
