<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PlatformAdminCommand extends Command
{
    protected $signature = 'bms:platform-admin {username : An existing company admin} {--revoke : Remove the platform-admin flag instead}';

    protected $description = 'Grant or revoke the right to create companies. Operator-only, there is no API for this';

    public function handle(): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        if ($user->role !== 'company_admin' && ! $this->option('revoke')) {
            $this->error('Only a company admin can be made a platform admin.');

            return self::FAILURE;
        }
        $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();
        $this->info($user->username.($this->option('revoke') ? ' is no longer a platform admin.' : ' is now a platform admin and can create companies.'));

        return self::SUCCESS;
    }
}
