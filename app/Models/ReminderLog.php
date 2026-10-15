<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReminderLog extends Model
{
    use BelongsToCompany;

    protected $table = 'reminder_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
