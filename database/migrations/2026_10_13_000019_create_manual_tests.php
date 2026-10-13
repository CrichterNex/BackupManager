<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tests a person performs by hand: for backups BMS cannot read (Microsoft 365, platform backups) and for any test that is not
 * automated. A manual test has a target to sample (a mailbox, say), a checklist, and evidence the tester attaches: fingerprints,
 * notes and files. The engine never claims a manual test, and it finishes through the same single write path as every other result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_test_executions', function (Blueprint $t) {
            $t->boolean('manual')->default(false)->after('test_type');
            $t->foreignId('assigned_to')->nullable()->after('executed_by')->constrained('users'); // who is doing it, once someone starts
            $t->string('target', 500)->nullable()->after('assigned_to');                         // what to sample: a mailbox, a site, a folder
            $t->json('checklist')->nullable()->after('details');                                // [{id, done}] for the steps ticked so far
            $t->index(['company_id', 'manual', 'test_status']);
        });

        Schema::table('test_schedules', fn (Blueprint $t) => $t->boolean('manual')->default(false)->after('test_type'));

        Schema::create('test_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('test_execution_id')->constrained('backup_test_executions');
            $t->string('step_id', 40)->nullable();                                              // the checklist step it belongs to
            $t->string('kind', 10);                                                             // hash | note | file
            $t->string('label', 200);
            $t->string('algorithm', 10)->nullable();                                            // hash: sha256 and friends
            $t->text('value_a')->nullable();                                                    // hash: the original's fingerprint. note: the text
            $t->text('value_b')->nullable();                                                    // hash: the restored copy's fingerprint
            $t->string('file_path', 255)->nullable();
            $t->string('file_name', 255)->nullable();
            $t->unsignedInteger('file_size')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index('test_execution_id');
        });
        DB::statement("ALTER TABLE test_evidence ADD CONSTRAINT valid_evidence_kind CHECK (kind IN ('hash','note','file'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('test_evidence');
        Schema::table('test_schedules', fn (Blueprint $t) => $t->dropColumn('manual'));
        Schema::table('backup_test_executions', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'manual', 'test_status']);
            $t->dropConstrainedForeignId('assigned_to');
            $t->dropColumn(['manual', 'target', 'checklist']);
        });
    }
};
