<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a server stores or does ("Finance database", "HR file shares"). It is what an anonymised report shows in place of the server's name,
 * so a report can go outside the company without saying which machine is which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->string('data_function', 255)->nullable());
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('data_function'));
    }
};
