<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestHoFeedbackAttachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'request_ho_feedback_id', 'file_path', 'original_name', 'mime_type', 'file_size',
    ];

    public function feedback()
    {
        return $this->belongsTo(RequestHoFeedback::class, 'request_ho_feedback_id');
    }
}
