<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestEmail extends Model
{
    use HasUuids;

    protected $fillable = [
        'request_submission_id',
        'requested_email',
        'purpose',
    ];

    public function submission()
    {
        return $this->belongsTo(
            RequestSubmission::class,
            'request_submission_id'
        );
    }
}
