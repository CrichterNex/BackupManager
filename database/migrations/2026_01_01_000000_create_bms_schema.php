<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BMS schema (single company per user, v3.0). Every tenant table carries company_id.
 * MariaDB has no row-level security, so isolation is enforced in the application (global scope plus
 * explicit company filters). The audit trail is still write-once, enforced here by triggers.
 */
return new class extends Migration
{
    private const METHODS = "'rsync','acronis','veeam','commvault','windows_manual_copy','linux_manual_copy','acronis_m365','microsoft_native_365','aws_backup','azure_backup'";

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug', 100)->unique();
            $t->string('contact_name')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->string('subscription_tier', 50)->default('basic');
            $t->unsignedInteger('max_servers')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::statement("ALTER TABLE companies ADD CONSTRAINT valid_tier CHECK (subscription_tier IN ('basic','pro','enterprise'))");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT valid_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')");

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            // Usernames are global because login happens before the company is known. The collation is case-insensitive.
            $t->string('username', 100)->unique();
            $t->string('email');
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('auth_method', 10)->default('local');
            $t->string('password')->nullable();
            $t->string('ldap_user', 100)->nullable();
            $t->string('role', 30);
            $t->boolean('active')->default(true);
            $t->timestamp('last_login')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT valid_role CHECK (role IN ('company_admin','company_manager','company_technician'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT valid_auth_method CHECK (auth_method IN ('local','ldap'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT local_needs_password CHECK (auth_method <> 'local' OR password IS NOT NULL)");

        Schema::create('servers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('hostname');
            $t->string('ip_address', 45)->nullable();
            $t->string('os_type', 20);
            $t->string('os_name', 100)->nullable();
            $t->string('criticality', 20)->default('standard');
            $t->json('backup_methods');
            $t->string('backup_location_path', 512)->nullable();
            $t->unsignedInteger('retention_policy_days')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('last_backup_time')->nullable();
            $t->string('last_backup_status', 20)->nullable();
            $t->timestamp('last_test_time')->nullable();
            $t->string('last_test_status', 20)->nullable();
            $t->text('notes')->nullable();
            // Hostnames are unique per company among active servers; NULL when inactive so retired names can be reused.
            $t->string('active_hostname')->nullable()->storedAs('CASE WHEN active = 1 THEN hostname ELSE NULL END');
            $t->timestamps();
            $t->unique(['company_id', 'active_hostname'], 'idx_servers_company_hostname');
        });
        DB::statement("ALTER TABLE servers ADD CONSTRAINT valid_os_type CHECK (os_type IN ('linux','windows'))");
        DB::statement("ALTER TABLE servers ADD CONSTRAINT valid_criticality CHECK (criticality IN ('critical','standard','non-critical'))");

        // Technicians only see servers they are assigned to.
        Schema::create('server_assignments', function (Blueprint $t) {
            $t->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained('companies');
            $t->primary(['server_id', 'user_id']);
        });

        Schema::create('backup_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('server_id')->constrained('servers');
            $t->string('backup_method', 50);
            $t->string('schedule', 100)->nullable();
            $t->unsignedInteger('retention_days')->nullable();
            $t->boolean('incremental')->default(false);
            $t->string('status', 20)->default('scheduled');
            $t->text('status_message')->nullable();
            $t->decimal('size_gb', 12, 2)->nullable();
            $t->unsignedInteger('files_count')->nullable();
            $t->timestamp('started_time')->nullable();
            $t->timestamp('completed_time')->nullable();
            $t->timestamp('recovery_point_date')->nullable();
            $t->string('test_status', 20)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->softDeletes('deleted_at');
            $t->index(['company_id', 'created_at']);
            $t->index(['server_id', 'created_at']);
            $t->index(['company_id', 'status']);
        });
        DB::statement('ALTER TABLE backup_jobs ADD CONSTRAINT valid_job_method CHECK (backup_method IN ('.self::METHODS.'))');
        DB::statement("ALTER TABLE backup_jobs ADD CONSTRAINT valid_job_status CHECK (status IN ('scheduled','running','success','warning','failed'))");

        Schema::create('backup_test_executions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('backup_job_id')->constrained('backup_jobs');
            $t->string('test_type', 50);
            $t->string('test_status', 20)->default('pending');
            $t->string('hash_algorithm', 20)->nullable();
            $t->string('hash_original', 128)->nullable();
            $t->string('hash_restored', 128)->nullable();
            $t->boolean('hash_match')->nullable();
            $t->timestamp('scheduled_time')->useCurrent();
            $t->timestamp('started_time')->nullable();
            $t->timestamp('completed_time')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->text('error_message')->nullable();
            $t->foreignId('executed_by')->nullable()->constrained('users'); // NULL = automated
            $t->text('notes')->nullable();
            $t->unsignedInteger('retry_count')->default(0);
            $t->unsignedBigInteger('retry_of')->nullable();
            $t->json('details')->nullable();
            $t->timestamps();
            $t->foreign('retry_of')->references('id')->on('backup_test_executions');
            $t->index(['company_id', 'created_at']);
            $t->index('backup_job_id');
            $t->index(['company_id', 'test_status']);
            $t->index('scheduled_time');
        });
        DB::statement("ALTER TABLE backup_test_executions ADD CONSTRAINT valid_test_type CHECK (test_type IN ('synthetic_file','random_restore','full_recovery','database_integrity','incremental_chain','m365_mailbox','recovery_point','file_permissions'))");
        DB::statement("ALTER TABLE backup_test_executions ADD CONSTRAINT valid_test_status CHECK (test_status IN ('pending','running','success','failed','skipped','cancelled'))");

        Schema::create('backup_test_failures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('test_execution_id')->constrained('backup_test_executions');
            $t->string('failure_type', 50);
            $t->string('severity', 20);
            $t->string('failed_item', 500)->nullable();
            $t->text('expected_value')->nullable();
            $t->text('actual_value')->nullable();
            $t->text('error_message')->nullable();
            $t->boolean('resolved')->default(false);
            $t->string('resolution_type', 50)->nullable();
            $t->text('resolution_notes')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestamp('resolved_at')->nullable();
            $t->boolean('backup_usable')->nullable();
            // 0 = not escalated, 1 = after 4 hours unresolved, 2 = after 8 hours.
            $t->unsignedTinyInteger('escalation_level')->default(0);
            $t->timestamps();
            $t->index(['company_id', 'resolved']);
        });
        DB::statement("ALTER TABLE backup_test_failures ADD CONSTRAINT valid_failure_type CHECK (failure_type IN ('file_missing','hash_mismatch','corruption','timeout','access_denied','restore_failed'))");
        DB::statement("ALTER TABLE backup_test_failures ADD CONSTRAINT valid_severity CHECK (severity IN ('critical','high','medium','low'))");
        DB::statement("ALTER TABLE backup_test_failures ADD CONSTRAINT valid_resolution CHECK (resolution_type IS NULL OR resolution_type IN ('retest_passed','backup_rerun','backup_fixed','false_positive','known_issue'))");
        DB::statement('ALTER TABLE backup_test_failures ADD CONSTRAINT resolved_has_detail CHECK (resolved = 0 OR (resolution_type IS NOT NULL AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL))');
        DB::statement('ALTER TABLE backup_test_failures ADD CONSTRAINT valid_escalation_level CHECK (escalation_level BETWEEN 0 AND 2)');

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('user_id')->constrained('users');
            $t->string('event_type', 50);
            $t->string('resource_type', 50)->nullable();
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->string('subject')->nullable();
            $t->text('message');
            $t->boolean('is_read')->default(false);
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'is_read', 'created_at']);
        });

        Schema::create('audit_trail', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies'); // NULL for events with no tenant (unknown-user logins)
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->string('action', 100);
            $t->string('resource_type', 50)->nullable();
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->json('details')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->boolean('success')->default(true);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'created_at']);
            $t->index('action');
            $t->index(['resource_type', 'resource_id']);
        });
        // The audit trail is write-once: block UPDATE and DELETE at the database, not just in the app.
        DB::unprepared("CREATE TRIGGER audit_trail_no_update BEFORE UPDATE ON audit_trail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_trail is immutable'");
        DB::unprepared("CREATE TRIGGER audit_trail_no_delete BEFORE DELETE ON audit_trail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_trail is immutable'");

        // Each row is a frozen snapshot of the figures at generation time.
        Schema::create('reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('report_type', 30);
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();
            $t->json('data');
            $t->foreignId('generated_by')->nullable()->constrained('users'); // NULL = generated automatically
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'report_type', 'period_start']);
        });
        DB::statement("ALTER TABLE reports ADD CONSTRAINT valid_report_type CHECK (report_type IN ('compliance','sla','security','dr_readiness'))");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_trail_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_trail_no_delete');
        foreach (['reports', 'audit_trail', 'notifications', 'backup_test_failures', 'backup_test_executions', 'backup_jobs', 'server_assignments', 'servers', 'users', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
