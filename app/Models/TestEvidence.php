<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TestEvidence extends Model
{
    use BelongsToCompany;

    protected $table = 'test_evidence';

    protected $guarded = [];

    /** The storage path is never shown: a file is only reachable through the download call, which checks who is asking. */
    protected $hidden = ['company_id', 'file_path'];
}
