<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stronger manual tests.
 *  - selection: the item the tester must open, chosen at random when the test is created ("the 3rd email received on 7 October").
 *  - findings: what the tester records about the item as structured fields (dates, subject, size) that BMS checks, with screenshots as proof.
 *  - verification: a random share of passed manual tests is checked by a second person, who confirms or rejects the evidence.
 *  - servers.timezone: the zone dates and times in the backup console are read in, for the day to pick and for checking what was entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->string('timezone', 64)->default('UTC')->after('os_name'));

        Schema::table('backup_test_executions', function (Blueprint $t) {
            $t->json('selection')->nullable()->after('reference');
            $t->json('findings')->nullable()->after('selection');
            $t->string('verify_state', 10)->nullable()->after('checklist');              // pending | confirmed | rejected, NULL when not asked for
            $t->foreignId('verified_by')->nullable()->after('verify_state')->constrained('users');
            $t->timestamp('verified_at')->nullable()->after('verified_by');
            $t->text('verify_note')->nullable()->after('verified_at');
            $t->index(['company_id', 'verify_state']);
        });
        DB::statement("ALTER TABLE backup_test_executions ADD CONSTRAINT valid_verify_state CHECK (verify_state IS NULL OR verify_state IN ('pending','confirmed','rejected'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backup_test_executions DROP CONSTRAINT valid_verify_state');
        Schema::table('backup_test_executions', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'verify_state']);
            $t->dropConstrainedForeignId('verified_by');
            $t->dropColumn(['selection', 'findings', 'verify_state', 'verified_at', 'verify_note']);
        });
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('timezone'));
    }
};
