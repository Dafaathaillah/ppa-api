<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class DeliverAsset extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'device_category',
        'departure_site',
        'arrived_site',
        'delivery_by',
        'received_by',
        'status',
        'link_ba',
        'note',
        'delivery_date',
        'received_date',
        'rejected_by',
        'rejected_at',
        'reject_reason',
    ];

    protected $casts = [

        'delivery_date' => 'datetime',

        'received_date' => 'datetime',

        'rejected_at' => 'datetime',

    ];

    public function laptop()
    {
        return $this->belongsTo(
            InvLaptop::class,
            'asset_id',
            'id'
        );
    }
}