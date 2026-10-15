<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Microsoft 365 tenant is backed up as several workloads (mailboxes, OneDrive, Teams, SharePoint), so one server needs several links on the same
 * connection. A server still cannot be linked to the same device twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_links', fn (Blueprint $t) => $t->unique(['server_id', 'integration_id', 'external_id'], 'server_link_device_unique'));
        Schema::table('server_links', fn (Blueprint $t) => $t->dropUnique(['server_id', 'integration_id']));
    }

    public function down(): void
    {
        Schema::table('server_links', fn (Blueprint $t) => $t->unique(['server_id', 'integration_id']));
        Schema::table('server_links', fn (Blueprint $t) => $t->dropUnique('server_link_device_unique'));
    }
};
