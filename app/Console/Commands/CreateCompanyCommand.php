<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Support\ApiException;
use App\Support\Passwords;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Provisions a tenant and its first admin. There is deliberately no API for this: the v3.0 model has no
// cross-company or platform-level role, so onboarding is an operator task run on the server.
class CreateCompanyCommand extends Command
{
    protected $signature = 'bms:company-create {--name=} {--slug=} {--tier=basic} {--admin=} {--email=} {--password=}';

    protected $description = 'Create a company and its first admin user';

    public function handle(): int
    {
        foreach (['name', 'slug', 'admin', 'email'] as $required) {
            if (! $this->option($required)) {
                $this->error('Required: --name --slug --admin --email   (optional: --tier --password)');

                return self::FAILURE;
            }
        }
        $generated = ! $this->option('password');
        $password = $this->option('password') ?: Str::random(16);
        try {
            Passwords::assertStrong($password);
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        try {
            $company = DB::transaction(function () use ($password) {
                $c = Company::create(['name' => $this->option('name'), 'slug' => $this->option('slug'), 'subscription_tier' => $this->option('tier'), 'contact_email' => $this->option('email')]);
                User::create([
                    'company_id' => $c->id, 'username' => $this->option('admin'), 'email' => $this->option('email'),
                    'role' => 'company_admin', 'auth_method' => 'local', 'password' => $password,
                ]);

                return $c;
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Created company {$company->id} \"{$company->name}\" with admin \"{$this->option('admin')}\".");
        if ($generated) {
            $this->line("Temporary password (shown once): $password");
        }

        return self::SUCCESS;
    }
}
