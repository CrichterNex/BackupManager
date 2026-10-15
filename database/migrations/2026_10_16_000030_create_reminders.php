<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail reminders. Each company chooses when a daily digest goes out and to whom; each person can opt out. The log records what was sent, so a
 * person gets one digest a day and an admin can see that it went (or why it did not).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained();
            $t->boolean('enabled')->default(true);
            $t->unsignedTinyInteger('send_hour')->default(8);          // 0-23, in the zone below
            $t->string('timezone', 64)->default('UTC');
            $t->boolean('weekdays_only')->default(true);
            $t->boolean('include_managers')->default(false);           // admins always; managers if asked; technicians get their own tests
            $t->timestamps();
        });
        Schema::create('reminder_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->constrained('users');
            $t->date('for_date');                                       // the day it was for, in the company's zone
            $t->string('status', 10);                                   // sent | failed | nothing (there was nothing to remind about)
            $t->unsignedInteger('items')->default(0);
            $t->string('error', 300)->nullable();
            $t->timestamp('sent_at')->useCurrent();
            $t->unique(['user_id', 'for_date']);
            $t->index(['company_id', 'sent_at']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->boolean('mail_reminders')->default(true));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('mail_reminders'));
        Schema::dropIfExists('reminder_log');
        Schema::dropIfExists('reminder_settings');
    }
};
