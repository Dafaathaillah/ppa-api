<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestApproval extends Model
{
    use HasUuids;

    protected $fillable = [

        'request_submission_id',

        'step',

        'approval_role',

        'approver_nik',

        'approver_name',

        'approver_email',

        'status',

        'comment',

        'signature',

        'token',

        'approved_at'

    ];

    protected $casts = [

        'approved_at' => 'datetime'

    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIP
    |--------------------------------------------------------------------------
    */

    public function submission()
    {
        return $this->belongsTo(
            RequestSubmission::class,
            'request_submission_id'
        );
    }

    public function token()
    {
        return $this->hasOne(
            RequestApprovalToken::class,
            'request_approval_id'
        );
    }
}
