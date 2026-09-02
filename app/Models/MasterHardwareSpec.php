<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MasterHardwareSpec extends Model
{
    use HasUuids;

    protected $fillable = [

        'hardware_code',

        'hardware_name',

        'category',

        'active'

    ];

    protected $casts = [

        'active' => 'boolean'

    ];
}