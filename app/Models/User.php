<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Not tenant-scoped: login has to find a user before the company is known. Controllers that list or
 * change users filter by company explicitly, and tests cover it.
 */
class User extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = [];

    protected $hidden = ['password', 'ldap_user'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'password' => 'hashed', 'last_login' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
