<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One line saying what a test found ("1,204 files, 18.2 GB read, all readable"), shown on the Tests page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_test_executions', fn (Blueprint $t) => $t->string('summary', 500)->nullable()->after('error_message'));
    }

    public function down(): void
    {
        Schema::table('backup_test_executions', fn (Blueprint $t) => $t->dropColumn('summary'));
    }
};
