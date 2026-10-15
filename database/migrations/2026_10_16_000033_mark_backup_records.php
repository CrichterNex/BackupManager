<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some backup_jobs rows are not jobs BMS manages: they are records of a backup made elsewhere (what a platform reported, or a stand-in that gives a test
 * by hand somewhere to live). They feed the dashboard, reports and tests, but the Backups page lists them as the platform's own rows, not as jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->boolean('is_record')->default(false));
        DB::table('backup_jobs')->where(fn ($q) => $q->where('status_message', 'like', 'Reported by %')->orWhere('status_message', 'like', 'Stand-in for tests%'))->update(['is_record' => true]);
    }

    public function down(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn('is_record'));
    }
};
