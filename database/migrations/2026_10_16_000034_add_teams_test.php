<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** One more test done by hand for Microsoft 365: restoring a message from a Microsoft Teams backup. */
return new class extends Migration
{
    private const OLD = "'synthetic_file','random_restore','full_recovery','database_integrity','incremental_chain','m365_mailbox','recovery_point','file_permissions','platform_validation','onedrive_file','sharepoint_site'";

    public function up(): void
    {
        DB::statement('ALTER TABLE backup_test_executions DROP CONSTRAINT valid_test_type');
        DB::statement('ALTER TABLE backup_test_executions ADD CONSTRAINT valid_test_type CHECK (test_type IN ('.self::OLD.",'teams_channel'))");
    }

    public function down(): void
    {
        DB::table('backup_test_executions')->where('test_type', 'teams_channel')->delete();
        DB::statement('ALTER TABLE backup_test_executions DROP CONSTRAINT valid_test_type');
        DB::statement('ALTER TABLE backup_test_executions ADD CONSTRAINT valid_test_type CHECK (test_type IN ('.self::OLD.'))');
    }
};
