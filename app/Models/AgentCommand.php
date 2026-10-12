<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AgentCommand extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $hidden = ['company_id', 'updated_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'deadline_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'exit_code' => 'integer'];
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
