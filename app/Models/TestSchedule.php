<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TestSchedule extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'manual' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }
}
