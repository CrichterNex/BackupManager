<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore tests that also compare the backed-up file with its source file. That needs three things BMS did not have:
 * how a job's backup tool lays the source out inside the destination (so a backup file can be traced to its source),
 * which source folders an agent allows itself to read, and where the comparison result is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $t) {
            // contents: the source folder's contents sit directly in the destination folder (typical robocopy/rsync of one folder).
            // by_folder_name: one sub-folder per source folder, named after it. full_path: the original path is recreated.
            $t->string('layout', 20)->nullable()->after('destination_path');
        });
        DB::statement("ALTER TABLE backup_jobs ADD CONSTRAINT valid_layout CHECK (layout IS NULL OR layout IN ('contents','by_folder_name','full_path'))");

        Schema::table('backup_test_executions', function (Blueprint $t) {
            $t->string('source_status', 20)->nullable()->after('hash_match'); // verified | mismatch | not_comparable. NULL = not attempted
            $t->string('source_note', 500)->nullable()->after('source_status');
        });
        Schema::table('agents', function (Blueprint $t) {
            $t->json('source_roots')->nullable()->after('locations'); // folders the agent allows itself to read when comparing with a source
        });
        Schema::table('agent_commands', function (Blueprint $t) {
            $t->json('payload')->nullable()->after('location_subpath'); // for source comparison: the job's folders, exclusions and layout
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', fn (Blueprint $t) => $t->dropColumn('payload'));
        Schema::table('agents', fn (Blueprint $t) => $t->dropColumn('source_roots'));
        Schema::table('backup_test_executions', fn (Blueprint $t) => $t->dropColumn(['source_status', 'source_note']));
        DB::statement('ALTER TABLE backup_jobs DROP CONSTRAINT valid_layout');
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn('layout'));
    }
};
