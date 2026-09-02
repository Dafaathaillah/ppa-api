<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestWorkflowStep extends Model
{
    use HasUuids;

    protected $fillable = [

        'request_type',

        'step',

        'approval_role',

        'is_required'

    ];
}