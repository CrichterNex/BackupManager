<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BackupJob extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'backup_jobs';

    protected $guarded = [];

    protected $hidden = ['company_id', 'created_by', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'incremental' => 'boolean',
            'size_gb' => 'float',
            'retention_days' => 'integer',
            'files_count' => 'integer',
            'started_time' => 'datetime',
            'completed_time' => 'datetime',
            'recovery_point_date' => 'datetime',
        ];
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
