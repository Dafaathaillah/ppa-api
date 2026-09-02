<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestHoFeedback extends Model
{
    use HasUuids;

    protected $table = 'request_ho_feedbacks';

    protected $fillable = [
        'request_submission_id', 'note', 'feedback_by_nrp',
        'feedback_by_name', 'completed_at',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    public function submission()
    {
        return $this->belongsTo(RequestSubmission::class, 'request_submission_id');
    }

    public function attachments()
    {
        return $this->hasMany(RequestHoFeedbackAttachment::class, 'request_ho_feedback_id');
    }
}
