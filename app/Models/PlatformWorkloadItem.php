<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PlatformWorkloadItem extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
