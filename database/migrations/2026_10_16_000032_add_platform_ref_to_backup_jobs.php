<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which part of a platform a backup record stands for ("m365:mailbox"), so a workload that is read many times a day keeps one record for the day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->string('platform_ref', 40)->nullable());
    }

    public function down(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn('platform_ref'));
    }
};
