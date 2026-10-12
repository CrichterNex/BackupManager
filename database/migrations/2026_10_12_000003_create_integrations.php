<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External backup platforms (Acronis first). A company stores one connection per platform account, then links
 * individual servers to a device on that platform. BMS reads the platform's own backup status for linked devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('platform', 30);
            $t->string('name');
            $t->string('base_url'); // e.g. https://eu2-cloud.acronis.com
            $t->text('credentials'); // encrypted with APP_KEY: client id and secret. Never returned by the API.
            $t->boolean('active')->default(true);
            $t->unsignedInteger('stale_after_hours')->default(36); // no successful backup this long = warning
            $t->timestamp('last_sync_at')->nullable();
            $t->string('last_sync_status', 20)->nullable(); // ok | error
            $t->text('last_sync_error')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['company_id', 'platform']);
        });

        Schema::create('server_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $t->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $t->string('external_id'); // the device's id on the platform
            $t->string('external_name')->nullable();
            $t->string('last_status', 50)->nullable(); // the platform's own status word, shown as reported
            $t->timestamp('last_run_at')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->string('sync_error')->nullable(); // set when the device could not be found at the last sync
            $t->timestamps();
            $t->unique(['server_id', 'integration_id']);
            $t->index(['integration_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_links');
        Schema::dropIfExists('integrations');
    }
};
