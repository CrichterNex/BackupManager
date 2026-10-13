<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A schedule can target a chosen set of servers, not only all of them, one server or one job. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_schedules', fn (Blueprint $t) => $t->json('server_ids')->nullable()->after('server_id'));
        DB::statement('ALTER TABLE test_schedules DROP CONSTRAINT valid_schedule_target');
        DB::statement("ALTER TABLE test_schedules ADD CONSTRAINT valid_schedule_target CHECK (target_type IN ('all','server','servers','job'))");
    }

    public function down(): void
    {
        DB::table('test_schedules')->where('target_type', 'servers')->update(['target_type' => 'all']);
        DB::statement('ALTER TABLE test_schedules DROP CONSTRAINT valid_schedule_target');
        DB::statement("ALTER TABLE test_schedules ADD CONSTRAINT valid_schedule_target CHECK (target_type IN ('all','server','job'))");
        Schema::table('test_schedules', fn (Blueprint $t) => $t->dropColumn('server_ids'));
    }
};
