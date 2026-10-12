<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional hands-off population: when on, each sync adds protected devices that are not yet in BMS as servers.
 * Off by default, because it creates servers (and counts against the subscription limit) without anyone picking them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $t) {
            $t->boolean('auto_import')->default(false)->after('stale_after_hours');
            $t->string('auto_import_os', 10)->default('windows')->after('auto_import'); // platform data does not say, so the admin chooses
        });
    }

    public function down(): void
    {
        Schema::table('integrations', fn (Blueprint $t) => $t->dropColumn(['auto_import', 'auto_import_os']));
    }
};
