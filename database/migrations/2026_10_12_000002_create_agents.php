<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remote agents. The agent only ever calls BMS (outbound HTTPS), runs only actions defined in its own local
 * config file, and BMS can trigger those by name only. BMS never sends a command line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $t->char('token_hash', 64)->unique(); // SHA-256 of the secret. The secret is shown once and never stored.
            $t->boolean('active')->default(true);
            $t->string('agent_version', 30)->nullable();
            $t->string('os_info', 255)->nullable();
            $t->json('actions')->nullable(); // what the agent says it can run: {name: {type: command|restore_test}}
            $t->timestamp('last_seen_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique('server_id'); // one agent per server
        });

        Schema::create('agent_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $t->string('type', 20);
            $t->string('action', 100);
            $t->string('status', 20)->default('pending');
            $t->foreignId('backup_job_id')->nullable()->constrained('backup_jobs');
            $t->foreignId('test_execution_id')->nullable()->constrained('backup_test_executions');
            $t->foreignId('requested_by')->nullable()->constrained('users'); // NULL = scheduled by the engine
            $t->timestamp('deadline_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->integer('exit_code')->nullable();
            $t->text('output')->nullable(); // last few KB only
            $t->json('details')->nullable();
            $t->timestamps();
            $t->index(['agent_id', 'status']);
            $t->index(['company_id', 'created_at']);
            $t->index('backup_job_id');
        });
        DB::statement("ALTER TABLE agent_commands ADD CONSTRAINT valid_command_type CHECK (type IN ('backup','restore_test'))");
        DB::statement("ALTER TABLE agent_commands ADD CONSTRAINT valid_command_status CHECK (status IN ('pending','running','success','failed','expired','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
        Schema::dropIfExists('agents');
    }
};
