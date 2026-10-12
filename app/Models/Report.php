<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'period_start' => 'datetime', 'period_end' => 'datetime', 'created_at' => 'datetime'];
    }
}
