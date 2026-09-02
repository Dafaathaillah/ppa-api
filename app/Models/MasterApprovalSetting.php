<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MasterApprovalSetting extends Model
{
    use HasUuids;

    protected $fillable = [

        'site',

        'request_type',

        'step',

        'approval_role',

        'approver_nrp',

        'approver_name',

        'backup_nrp',

        'backup_name',

        'active'

    ];

    protected $casts=[

        'active'=>'boolean'

    ];
}