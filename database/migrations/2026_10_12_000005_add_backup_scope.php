<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a backup job covers (the whole server, or specific folders) and where inside its backup location the data
 * lands. The scope is what the admin says the job covers, and BMS shows it in views and reports. BMS does not
 * enforce it on the backup tool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $t) {
            $t->string('scope_type', 20)->nullable()->after('location_id'); // NULL = not specified yet
            $t->json('source_paths')->nullable()->after('scope_type'); // the folders, when scope_type is "folders"
            $t->string('destination_path', 255)->nullable()->after('source_paths'); // relative to the location, e.g. web-01/daily
        });
        DB::statement("ALTER TABLE backup_jobs ADD CONSTRAINT valid_scope_type CHECK (scope_type IS NULL OR scope_type IN ('full_server','folders'))");

        // A restore-test command carries the job's destination folder as a relative sub-path under the location key.
        Schema::table('agent_commands', function (Blueprint $t) {
            $t->string('location_subpath', 255)->nullable()->after('location_key');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', fn (Blueprint $t) => $t->dropColumn('location_subpath'));
        DB::statement('ALTER TABLE backup_jobs DROP CONSTRAINT valid_scope_type');
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn(['scope_type', 'source_paths', 'destination_path']));
    }
};
