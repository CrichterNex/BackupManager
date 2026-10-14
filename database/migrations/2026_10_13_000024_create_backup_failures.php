<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backup problems somebody has to review. A backup that failed, that its platform calls critical, that is overdue, or that the platform warns about
 * raises one of these, and it stays open until a person reviews it and says what they found, even if the backup recovers on its own. One row per
 * server, source and backup method at a time, updated while the problem lasts, so a persistent problem is one item to review and not one per sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_failures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('server_id')->constrained('servers');
            $t->foreignId('backup_job_id')->nullable()->constrained('backup_jobs');
            $t->string('source', 10);                                   // acronis | agent
            $t->string('backup_method', 30);
            $t->string('kind', 10);                                     // failed | critical | overdue | warning
            $t->string('severity', 10);
            $t->text('message');
            $t->timestamp('first_seen_at')->useCurrent();
            $t->timestamp('last_seen_at')->useCurrent();
            $t->timestamp('last_good_at')->nullable();                  // the last good backup before the problem began
            $t->unsignedInteger('interval_seconds')->nullable();        // how often a backup is expected, from the schedule or the device's own history
            $t->unsignedInteger('gap_hours')->nullable();               // hours without a good backup: so far while it lasts, in total once it recovers
            $t->unsignedSmallInteger('missed_runs')->default(0);        // scheduled backups skipped in that time
            $t->boolean('active')->default(true);                       // the problem is still there
            $t->timestamp('recovered_at')->nullable();                  // it went away by itself; it still needs reviewing
            $t->boolean('resolved')->default(false);
            $t->string('resolution_type', 20)->nullable();
            $t->text('resolution_notes')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedTinyInteger('escalation_level')->default(0);
            $t->timestamps();
            $t->index(['company_id', 'resolved']);
            $t->index(['server_id', 'source', 'backup_method']);
        });
        DB::statement("ALTER TABLE backup_failures ADD CONSTRAINT valid_bf_source CHECK (source IN ('acronis','agent'))");
        DB::statement("ALTER TABLE backup_failures ADD CONSTRAINT valid_bf_kind CHECK (kind IN ('failed','critical','overdue','warning'))");
        DB::statement("ALTER TABLE backup_failures ADD CONSTRAINT valid_bf_severity CHECK (severity IN ('critical','high','medium','low'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_failures');
    }
};
