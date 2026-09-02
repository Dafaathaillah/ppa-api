<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvCctv extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'inventory_number',
        'cctv_code',
        'number_asset_ho',
        'location',
        'location_detail',
        'cctv_name',
        'cctv_brand',
        'cctv_type',
        'mac_address',
        'ip_address',
        'vlan',
        'nvr_id',
        'uplink',
        'inspection_remark',
        'status',
        'remark',
        'site',
        'date_of_inventory',
        'last_update_at',
        'last_update_by',
    ];

    public function inspeksis()
    {
        return $this->hasMany(InspeksiCctv::class, 'cctv_id', 'id');
    }
    
    public function nvr()
    {
        return $this->belongsTo(InvNvr::class, 'nvr_id', 'id');
    }
}
