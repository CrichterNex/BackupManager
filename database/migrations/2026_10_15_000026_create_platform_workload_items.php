<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microsoft 365 backups in Acronis are reported one activity per mailbox, OneDrive, Team or SharePoint site, thousands a day. BMS keeps only the latest
 * result of each item here and summarises them per kind of workload, so a status is one line and not thousands. The name of an item is kept only when
 * its latest backup had a problem, because that is the only time a person needs it.
 * integrations.activity_through is how far the activity log has been read, so a sync knows whether it is catching up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_workload_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $t->string('workload', 12);                       // mailbox | onedrive | teams | sharepoint
            $t->string('resource_id', 64);                    // the platform's id for the mailbox, drive, team or site
            $t->string('plan_name', 300)->nullable();
            $t->string('result', 12);                         // ok | warning | error | cancelled
            $t->string('error_code', 100)->nullable();
            $t->string('message', 300)->nullable();
            $t->string('name', 255)->nullable();              // only while the latest backup had a problem
            $t->string('activity_uuid', 40)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['integration_id', 'workload', 'resource_id'], 'workload_item_unique');
            $t->index(['integration_id', 'workload', 'completed_at'], 'workload_item_recent');
        });
        Schema::table('integrations', fn (Blueprint $t) => $t->timestamp('activity_through')->nullable());
    }

    public function down(): void
    {
        Schema::table('integrations', fn (Blueprint $t) => $t->dropColumn('activity_through'));
        Schema::dropIfExists('platform_workload_items');
    }
};
