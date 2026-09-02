<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestAccess extends Model
{
    use HasUuids;

    protected $fillable = [
        'request_submission_id', 'requirement', 'period_type',
        'temporary_reason', 'start_date', 'end_date', 'purpose',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function submission()
    {
        return $this->belongsTo(RequestSubmission::class, 'request_submission_id');
    }
}
