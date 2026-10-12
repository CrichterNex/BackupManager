<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Write-once: the database rejects UPDATE and DELETE on audit_trail. */
class AuditEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'audit_trail';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'success' => 'boolean', 'created_at' => 'datetime'];
    }
}
