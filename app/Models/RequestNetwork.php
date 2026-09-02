<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestNetwork extends Model
{
    use HasUuids;

    protected $fillable = ['request_submission_id', 'requirements', 'purpose'];

    protected $casts = ['requirements' => 'array'];

    public function submission()
    {
        return $this->belongsTo(RequestSubmission::class, 'request_submission_id');
    }
}
