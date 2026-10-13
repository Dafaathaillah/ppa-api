<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InvNvr extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'inventory_number',
        'number_asset_ho',
        'nvr_name',
        'nvr_brand',
        'nvr_type',
        'mac_address',
        'ip_address',
        'jumlah_hdd',
        'total_capacity',
        'location',
        'jumlah_channel',
        'channel_used',
        'channel_free',
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
        return $this->hasMany(InspeksiNvr::class, 'nvr_id', 'id');
    }

    public function cctvs()
    {
        return $this->hasMany(InvCctv::class, 'nvr_id', 'id');
    }

    public function viewOnlyCctvs()
    {
        return $this->belongsToMany(InvCctv::class, 'nvr_cctv_view_only', 'nvr_id', 'cctv_id')
            ->using(NvrCctvViewOnly::class)->withTimestamps();
    }
}
