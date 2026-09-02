<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvStarlink extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'max_id',
        'device_name',
        'inventory_number',
        'asset_type',
        'kit_number',
        'device_type',
        'subscription_type',
        'location',
        'mikrotik_ip',
        'mikrotik_mac',
        'status',
        'remark',
        'site',
    ];

    protected $casts = [
        'max_id' => 'integer',
    ];
}
