<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Folders an agent may read for backups and source comparison, set in BMS instead of in the agent's config file. Same opt-in as managed actions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->json('managed_source_roots')->nullable()->after('managed_locations'));
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropColumn('managed_source_roots'));
    }
};
