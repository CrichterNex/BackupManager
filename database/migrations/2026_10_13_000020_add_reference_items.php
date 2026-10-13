<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference items: known files kept in a tenant's dedicated test mailboxes, with the fingerprint (hash) recorded when they were created.
 * A tester restores one and enters only the restored copy's fingerprint, and BMS compares it with the expected one. Nobody has to open a
 * real person's mailbox or ask its owner for the original. Each manual test keeps a snapshot of the item it was given, so editing the list
 * later never changes a test that has been handed out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->json('reference_items')->nullable()->after('test_targets'));
        Schema::table('backup_test_executions', fn (Blueprint $t) => $t->json('reference')->nullable()->after('target'));
    }

    public function down(): void
    {
        Schema::table('backup_test_executions', fn (Blueprint $t) => $t->dropColumn('reference'));
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('reference_items'));
    }
};
