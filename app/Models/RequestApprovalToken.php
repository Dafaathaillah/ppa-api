<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestApprovalToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'request_submission_id',
        'request_approval_id',
        'approver_nrp',
        'approver_name',
        'token',
        'expired_at',
        'used_at',
        'ip_address',
        'browser',
    ];

    protected $casts = [

        'expired_at' => 'datetime',

        'used_at' => 'datetime',

    ];

    public function submission()
    {
        return $this->belongsTo(
            RequestSubmission::class,
            'request_submission_id'
        );
    }

    public function approval()
    {
        return $this->belongsTo(
            RequestApproval::class,
            'request_approval_id'
        );
    }
}