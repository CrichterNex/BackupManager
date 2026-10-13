<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which test an agent is asked to run: an agent can run more than one kind of test now. NULL (older commands) means a random restore. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', fn (Blueprint $t) => $t->string('test_type', 30)->nullable()->after('type'));
    }

    public function down(): void
    {
        Schema::table('agent_commands', fn (Blueprint $t) => $t->dropColumn('test_type'));
    }
};
