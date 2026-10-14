<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BackupFailure extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'resolved' => 'boolean', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'recovered_at' => 'datetime', 'resolved_at' => 'datetime', 'last_good_at' => 'datetime'];
    }
}
