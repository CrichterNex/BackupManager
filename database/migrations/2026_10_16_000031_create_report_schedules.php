<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reports that make themselves: a report type, when to run, and which period it covers. Each run saves a report like any other. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('name', 100);
            $t->string('report_type', 20);
            $t->string('period', 20)->nullable();            // previous_week | previous_month | previous_quarter | last_30 | last_90; none for a readiness snapshot
            $t->string('frequency', 10);                      // weekly | monthly | quarterly | custom
            $t->string('run_time', 5)->nullable();
            $t->unsignedTinyInteger('run_day')->nullable();   // weekday 0-6 for weekly, day of month 1-28 otherwise
            $t->string('cron', 100);
            $t->string('timezone', 64)->default('UTC');
            $t->boolean('enabled')->default(true);
            $t->timestamp('next_run_at')->nullable();
            $t->timestamp('last_run_at')->nullable();
            $t->foreignId('last_report_id')->nullable()->constrained('reports')->nullOnDelete();
            $t->string('last_error', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
