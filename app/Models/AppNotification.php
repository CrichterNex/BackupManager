<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    use BelongsToCompany;

    protected $table = 'notifications';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['is_read' => 'boolean', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
