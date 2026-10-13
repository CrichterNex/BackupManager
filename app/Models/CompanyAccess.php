<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A grant letting one user work in another company with a given role. Deliberately not tenant-scoped: it joins a
 * user in one company to a second company, so it is only reached through the platform-admin and "my access" code.
 */
class CompanyAccess extends Model
{
    protected $table = 'company_access';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
