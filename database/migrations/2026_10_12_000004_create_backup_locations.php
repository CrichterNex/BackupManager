<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backup locations: named destinations that BMS-managed backups (rsync, robocopy, manual copies, ...) write to, and
 * that restore tests read from. Backups made by a platform such as Acronis never use these: they use the
 * locations configured on the platform itself.
 *
 * A location has a short key. For remote servers BMS sends only the key to the agent, and the agent maps it to a
 * path on its own machine, so BMS never sends a path to run against.
 */
return new class extends Migration
{
    private const METHODS = "'rsync','acronis','veeam','commvault','windows_manual_copy','linux_manual_copy','robocopy','manual_copy','acronis_m365','microsoft_native_365','aws_backup','azure_backup'";

    private const LOCATION_METHODS = ['rsync', 'windows_manual_copy', 'linux_manual_copy', 'robocopy', 'manual_copy'];

    public function up(): void
    {
        Schema::create('backup_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('name');
            $t->string('loc_key', 100); // what the agent's config maps to a local path
            $t->string('kind', 30)->default('other');
            $t->string('path', 512)->nullable(); // as the BMS server sees it (local or mounted share); empty if only the agent can reach it
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'loc_key']);
            $t->unique(['company_id', 'name']);
        });
        DB::statement("ALTER TABLE backup_locations ADD CONSTRAINT valid_loc_key CHECK (loc_key REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE backup_locations ADD CONSTRAINT valid_loc_kind CHECK (kind IN ('local_disk','network_share','nas','external_drive','offsite','other'))");

        Schema::table('backup_jobs', function (Blueprint $t) {
            $t->foreignId('location_id')->nullable()->after('backup_method')->constrained('backup_locations');
        });

        // Agents report which location keys they can map to a local path, and restore-test commands carry the key.
        Schema::table('agents', function (Blueprint $t) {
            $t->json('locations')->nullable()->after('actions');
        });
        Schema::table('agent_commands', function (Blueprint $t) {
            $t->string('location_key', 100)->nullable()->after('action');
        });

        DB::statement('ALTER TABLE backup_jobs DROP CONSTRAINT valid_job_method');
        DB::statement('ALTER TABLE backup_jobs ADD CONSTRAINT valid_job_method CHECK (backup_method IN ('.self::METHODS.'))');

        // Carry over the old single path on each server: it becomes a location used by that server's file-copy jobs.
        $used = [];
        foreach (DB::table('servers')->whereNotNull('backup_location_path')->get(['id', 'company_id', 'hostname', 'backup_location_path']) as $s) {
            $base = Str::slug($s->hostname) ?: 'server-'.$s->id;
            $key = $base;
            for ($i = 2; isset($used[$s->company_id.'/'.$key]); $i++) {
                $key = "$base-$i";
            }
            $used[$s->company_id.'/'.$key] = true;
            $id = DB::table('backup_locations')->insertGetId([
                'company_id' => $s->company_id, 'name' => $s->hostname.' backups', 'loc_key' => $key, 'kind' => 'other',
                'path' => $s->backup_location_path, 'notes' => 'Moved from the server\'s old backup path setting.', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('backup_jobs')->where('server_id', $s->id)->whereIn('backup_method', self::LOCATION_METHODS)->update(['location_id' => $id]);
        }
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('backup_location_path'));
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->string('backup_location_path', 512)->nullable());
        DB::statement('ALTER TABLE backup_jobs DROP CONSTRAINT valid_job_method');
        DB::statement("ALTER TABLE backup_jobs ADD CONSTRAINT valid_job_method CHECK (backup_method IN ('rsync','acronis','veeam','commvault','windows_manual_copy','linux_manual_copy','acronis_m365','microsoft_native_365','aws_backup','azure_backup'))");
        Schema::table('agent_commands', fn (Blueprint $t) => $t->dropColumn('location_key'));
        Schema::table('agents', fn (Blueprint $t) => $t->dropColumn('locations'));
        Schema::table('backup_jobs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('location_id');
        });
        Schema::dropIfExists('backup_locations');
    }
};
