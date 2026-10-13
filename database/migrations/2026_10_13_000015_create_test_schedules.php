<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Recurring tests an admin sets up: which backups, which test, how often. The engine queues them when they fall due. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('name', 100);
            $t->string('test_type', 30);
            $t->string('target_type', 10);                                   // all | server | job
            $t->foreignId('server_id')->nullable()->constrained('servers');
            $t->foreignId('backup_job_id')->nullable()->constrained('backup_jobs');
            $t->string('frequency', 10);                                     // daily | weekly | monthly | custom
            $t->string('run_time', 5)->nullable();                           // HH:MM, in the schedule's time zone
            $t->unsignedTinyInteger('run_day')->nullable();                  // weekly: 0 (Sunday) to 6, monthly: 1 to 28
            $t->string('cron', 100);                                         // what the engine reads, built from the fields above unless custom
            $t->string('timezone', 64)->default('UTC');
            $t->boolean('enabled')->default(true);
            $t->timestamp('next_run_at')->nullable();                        // NULL while paused
            $t->timestamp('last_run_at')->nullable();
            $t->unsignedInteger('last_queued')->default(0);                  // how many tests the last run queued
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['company_id', 'enabled', 'next_run_at']);
        });
        DB::statement("ALTER TABLE test_schedules ADD CONSTRAINT valid_schedule_target CHECK (target_type IN ('all','server','job'))");
        DB::statement("ALTER TABLE test_schedules ADD CONSTRAINT valid_schedule_frequency CHECK (frequency IN ('daily','weekly','monthly','custom'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('test_schedules');
    }
};
