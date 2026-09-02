<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestHardware extends Model
{
    use HasUuids;

    protected $fillable = [

        'request_submission_id',

        'hardware_type',

        'purpose',

        'previous_asset_number',

        'previous_device',

        'replace_reason',

        'daily_job',

        'weekly_job',

        'monthly_job'

    ];

    public function submission()
    {
        return $this->belongsTo(RequestSubmission::class);
    }
}