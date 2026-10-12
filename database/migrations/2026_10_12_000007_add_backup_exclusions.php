<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders a job deliberately leaves out, such as C:\Folder\Sub1 inside a backed-up C:\Folder. Like the rest of the
 * scope, this is what the admin states. BMS records and reports it and does not enforce it on the backup tool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $t) {
            $t->json('excluded_paths')->nullable()->after('source_paths');
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn('excluded_paths'));
    }
};
