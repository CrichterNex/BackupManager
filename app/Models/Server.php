<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $hidden = ['company_id', 'active', 'active_hostname'];

    protected $appends = ['status'];

    /** Derived health for list filtering and sorting. Keep in step with getStatusAttribute(). */
    public const STATUS_SQL = "CASE
        WHEN servers.last_backup_status = 'failed' OR servers.last_test_status = 'failed' THEN 'failing'
        WHEN servers.last_backup_status IN ('success', 'warning') AND servers.last_test_status = 'passed' THEN 'healthy'
        WHEN servers.last_backup_status IN ('success', 'warning') AND servers.last_test_status IS NULL THEN 'pending_test'
        ELSE 'unknown' END";

    protected function casts(): array
    {
        return [
            'backup_methods' => 'array',
            'active' => 'boolean',
            'retention_policy_days' => 'integer',
            'last_backup_time' => 'datetime',
            'last_test_time' => 'datetime',
        ];
    }

    public function getStatusAttribute(): string
    {
        $b = $this->last_backup_status;
        $t = $this->last_test_status;
        if ($b === 'failed' || $t === 'failed') {
            return 'failing';
        }
        if (in_array($b, ['success', 'warning'], true) && $t === 'passed') {
            return 'healthy';
        }
        if (in_array($b, ['success', 'warning'], true) && $t === null) {
            return 'pending_test';
        }

        return 'unknown';
    }
}
