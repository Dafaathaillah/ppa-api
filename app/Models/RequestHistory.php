<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestHistory extends Model
{
    use HasUuids;

    protected $fillable = [

        'request_submission_id',

        'activity',

        'performed_by',

        'performed_name',

        'ip_address',

        'browser',

        'description'

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