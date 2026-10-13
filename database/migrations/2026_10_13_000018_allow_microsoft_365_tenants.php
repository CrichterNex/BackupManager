<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Microsoft 365 tenant is protected like a server but is not a machine: no agent, no IP address, no operating system. It is stored as a
 * server of type "microsoft_365" so its jobs, schedules, tests and reports work unchanged. test_targets is the list of mailboxes or sites
 * a person samples when testing it (one is picked each time, the one tested least recently).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE servers DROP CONSTRAINT valid_os_type');
        DB::statement("ALTER TABLE servers ADD CONSTRAINT valid_os_type CHECK (os_type IN ('linux','windows','microsoft_365'))");
        Schema::table('servers', fn (Blueprint $t) => $t->json('test_targets')->nullable()->after('notes'));
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('test_targets'));
        DB::statement('ALTER TABLE servers DROP CONSTRAINT valid_os_type');
        DB::statement("ALTER TABLE servers ADD CONSTRAINT valid_os_type CHECK (os_type IN ('linux','windows'))");
    }
};
