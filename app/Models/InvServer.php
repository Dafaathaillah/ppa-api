<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvServer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'max_id',
        'asset_ho_number',
        'server_code',
        'serial_number',
        'ip_address',
        'server_name',
        'server_brand',
        'category_id',
        'server_type',
        'location',
        'date_of_inventory',
        'date_of_deploy',
        'status',
        'service_tag',
        'cpu',
        'ram',
        'hdd',
        'os',
        'inspection_remark',
        'site',
    ];
    public function serverCategory()
    {
        return $this->belongsTo(
            ServerCategory::class,
            'category_id',
            'id'
        );
    }

    public function inspeksi()
    {
        return $this->hasMany(
            InspeksiServer::class,
            'inv_server_id',
            'id'
        );
    }
}
