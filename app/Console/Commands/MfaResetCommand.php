<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Mfa;
use Illuminate\Console\Command;

class MfaResetCommand extends Command
{
    protected $signature = 'bms:mfa-reset {username : The person who lost their authenticator app}';

    protected $description = 'Remove a user\'s two-step sign-in and end their sessions. The last resort when no admin can sign in';

    public function handle(): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        Mfa::reset($user);
        $this->info($user->username.' is signed out everywhere and must set up two-step sign-in again at their next sign-in.');

        return self::SUCCESS;
    }
}
