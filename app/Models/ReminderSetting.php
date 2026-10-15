<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReminderSetting extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'weekdays_only' => 'boolean', 'include_managers' => 'boolean', 'send_hour' => 'integer'];
    }
}
