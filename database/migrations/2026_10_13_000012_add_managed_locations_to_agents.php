<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a backup location's key points on a server, set in BMS instead of in the agent's config file. Same opt-in as managed actions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->json('managed_locations')->nullable()->after('managed_actions'));
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropColumn('managed_locations'));
    }
};
