<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    /** The credentials never leave the server. Only a masked hint is shown, so an admin can tell connections apart. */
    protected $hidden = ['credentials', 'company_id'];

    protected $appends = ['client_id_hint'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'active' => 'boolean', 'stale_after_hours' => 'integer', 'last_sync_at' => 'datetime'];
    }

    public function getClientIdHintAttribute(): ?string
    {
        $id = $this->credentials['client_id'] ?? null;

        return $id ? '…'.substr($id, -4) : null;
    }

    public function links()
    {
        return $this->hasMany(ServerLink::class);
    }
}
