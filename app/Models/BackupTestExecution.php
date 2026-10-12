<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BackupTestExecution extends Model
{
    use BelongsToCompany;

    protected $table = 'backup_test_executions';

    protected $guarded = [];

    protected $hidden = ['company_id', 'notes', 'details', 'updated_at'];

    protected function casts(): array
    {
        return [
            'hash_match' => 'boolean',
            'duration_seconds' => 'integer',
            'retry_count' => 'integer',
            'details' => 'array',
            'scheduled_time' => 'datetime',
            'started_time' => 'datetime',
            'completed_time' => 'datetime',
        ];
    }

    public function job()
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }
}
