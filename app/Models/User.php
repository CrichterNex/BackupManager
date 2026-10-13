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

    protected $hidden = ['password', 'ldap_user', 'mfa_secret', 'mfa_pending_secret', 'mfa_enabled_at', 'mfa_last_step', 'mfa_recovery_codes'];

    /**
     * The role this request acts with. Normally the user's own role. Inside another company through an access grant it
     * is the granted role. It lives only on this object for the length of the request and is never saved.
     */
    public ?string $actingRole = null;

    protected function casts(): array
    {
        return [
            'active' => 'boolean', 'is_platform_admin' => 'boolean', 'password' => 'hashed', 'last_login' => 'datetime',
            'mfa_secret' => 'encrypted', 'mfa_pending_secret' => 'encrypted', 'mfa_enabled_at' => 'datetime',
        ];
    }

    public function effectiveRole(): string
    {
        return $this->actingRole ?? $this->role;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function accesses()
    {
        return $this->hasMany(CompanyAccess::class);
    }
}
