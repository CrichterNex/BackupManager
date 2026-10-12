<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BackupTestFailure extends Model
{
    use BelongsToCompany;

    protected $table = 'backup_test_failures';

    protected $guarded = [];

    protected $hidden = ['company_id', 'updated_at'];

    protected function casts(): array
    {
        return [
            'resolved' => 'boolean',
            'backup_usable' => 'boolean',
            'escalation_level' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }
}
