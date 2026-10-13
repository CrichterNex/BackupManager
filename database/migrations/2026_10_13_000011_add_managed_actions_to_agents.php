<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup commands that an admin sets in BMS instead of in the agent's config file. Only an agent that was installed with
 * remote actions on (its own config says so, and it reports that on every check-in) will ever use them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $t) {
            $t->json('managed_actions')->nullable()->after('actions');
            $t->boolean('remote_actions')->nullable()->after('managed_actions'); // what the agent last said; null for agents too old to say
        });
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropColumn(['managed_actions', 'remote_actions']));
    }
};
