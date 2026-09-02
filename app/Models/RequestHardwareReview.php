<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestHardwareReview extends Model
{
    use HasUuids;

    protected $fillable = [

        'request_submission_id',

        'master_hardware_spec_id',

        'note',

        'review_by',

        'review_name',

        'review_at'

    ];

    public function masterHardwareSpec()
    {
        return $this->belongsTo(
            MasterHardwareSpec::class,
            'master_hardware_spec_id'
        );
    }

    public function submission()
    {
        return $this->belongsTo(
            RequestSubmission::class,
            'request_submission_id'
        );
    }
}