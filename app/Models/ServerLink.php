<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ServerLink extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime', 'last_success_at' => 'datetime', 'synced_at' => 'datetime'];
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
