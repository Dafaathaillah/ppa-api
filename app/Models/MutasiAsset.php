<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MutasiAsset extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'device_category',
        'mutation_by',
        'accepted_by',
        'departure_site',
        'arrived_site',
        'approval_status',
        'rejected_status',
        'rejected_by',
        'rejected_at',
        'created_date',
        'link_ba',
        'pic_username',
        'pic_nrp',
        'pic_position',
        'pic_department',
        'note',
    ];

    public function laptop()
    {
        return $this->belongsTo(InvLaptop::class, 'asset_id', 'id');
    }

    public function computer()
    {
        return $this->belongsTo(InvComputer::class, 'asset_id', 'id');
    }
}
