<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform admin can create companies. It is a flag on an ordinary user, not a role: the user still
 * belongs to one company and is still limited to it for everything else. Set only by an operator with
 * `php artisan bms:platform-admin <username>`, never through the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_platform_admin')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_platform_admin'));
    }
};
