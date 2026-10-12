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

    /**
     * Derived health for list filtering and sorting. A test result is a point in time, so a passing test only counts as
     * current for a while (BMS_TEST_FRESH_DAYS, default 14). After that the server shows "test_overdue" instead of
     * "healthy", because nothing has checked the backup since. Keep in step with getStatusAttribute().
     */
    public static function statusSql(): string
    {
        $cutoff = now()->subDays(config('bms.test_fresh_days'))->format('Y-m-d H:i:s');

        return "CASE
        WHEN servers.last_backup_status = 'failed' OR servers.last_test_status = 'failed' THEN 'failing'
        WHEN servers.last_backup_status IN ('success', 'warning') AND servers.last_test_status = 'passed' AND servers.last_test_time >= '$cutoff' THEN 'healthy'
        WHEN servers.last_backup_status IN ('success', 'warning') AND servers.last_test_status = 'passed' THEN 'test_overdue'
        WHEN servers.last_backup_status IN ('success', 'warning') AND servers.last_test_status IS NULL THEN 'pending_test'
        ELSE 'unknown' END";
    }

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
            $fresh = $this->last_test_time !== null && $this->last_test_time->gte(now()->subDays(config('bms.test_fresh_days')));

            return $fresh ? 'healthy' : 'test_overdue';
        }
        if (in_array($b, ['success', 'warning'], true) && $t === null) {
            return 'pending_test';
        }

        return 'unknown';
    }
}
