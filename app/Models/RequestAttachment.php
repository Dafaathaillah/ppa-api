<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestAttachment extends Model
{
    use HasUuids;

    protected $fillable = [

        'request_submission_id',

        'file_name',

        'file_path',

        'file_type',

        'file_size',

        'uploaded_by'

    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIP
    |--------------------------------------------------------------------------
    */

    public function submission()
    {
        return $this->belongsTo(RequestSubmission::class);
    }
}