<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BackupLocation extends Model
{
    use BelongsToCompany;

    protected $table = 'backup_locations';

    public const KINDS = ['local_disk', 'network_share', 'nas', 'external_drive', 'offsite', 'other'];

    protected $guarded = [];

    protected $hidden = ['company_id'];

    public function jobs()
    {
        return $this->hasMany(BackupJob::class, 'location_id');
    }
}
