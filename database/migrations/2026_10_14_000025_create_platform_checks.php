<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a backup platform says about its own checks of the backups: Acronis validation runs (a run for a device, and the validation of each backup
 * within it) and replication. Read from the platform's activity log on each sync and kept so a test can judge a device by its latest validation.
 * integrations.activity_cursor is where the last read stopped, so a sync reads what is new and not the whole history.
 */
return new class extends Migration
{
    private const TYPES_OLD = "'synthetic_file','random_restore','full_recovery','database_integrity','incremental_chain','m365_mailbox','recovery_point','file_permissions'";

    public function up(): void
    {
        Schema::create('platform_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('integration_id')->constrained('integrations');
            $t->foreignId('server_id')->nullable()->constrained('servers');   // the linked server, when the device is one BMS knows
            $t->string('uuid', 40)->unique();                                  // the platform's id for the activity
            $t->string('parent_uuid', 40)->nullable();                         // the validation run a backup's validation belongs to
            $t->string('kind', 16);                                            // validation_run | validation | replication
            $t->string('state', 12)->nullable();                               // started | completed
            $t->string('result', 10)->nullable();                              // ok | warning | error, once finished
            $t->string('error_code', 100)->nullable();
            $t->string('detail', 500)->nullable();                             // where it failed, when the platform says (the location)
            $t->string('plan_name', 300)->nullable();
            $t->string('resource_id', 64)->nullable();
            $t->string('resource_name', 255)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['server_id', 'kind', 'started_at']);
            $t->index('parent_uuid');
        });
        Schema::table('integrations', fn (Blueprint $t) => $t->text('activity_cursor')->nullable());

        DB::statement('ALTER TABLE backup_test_executions DROP CONSTRAINT valid_test_type');
        DB::statement('ALTER TABLE backup_test_executions ADD CONSTRAINT valid_test_type CHECK (test_type IN ('.self::TYPES_OLD.",'platform_validation'))");
    }

    public function down(): void
    {
        DB::table('backup_test_executions')->where('test_type', 'platform_validation')->delete();
        DB::statement('ALTER TABLE backup_test_executions DROP CONSTRAINT valid_test_type');
        DB::statement('ALTER TABLE backup_test_executions ADD CONSTRAINT valid_test_type CHECK (test_type IN ('.self::TYPES_OLD.'))');
        Schema::table('integrations', fn (Blueprint $t) => $t->dropColumn('activity_cursor'));
        Schema::dropIfExists('platform_checks');
    }
};
