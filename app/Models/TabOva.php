<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TabOva extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'tab_ovas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'unit_id',
        'max_id',
        'cn_unit',
        'sn_tab',
        'merk_tab',
        'type_tab',
        'no_asset_tab',
        'nomor_sim',
        'pic_tab',
        'lokasi_tab',
        'remark',
        'link_foto',
        'site',
        'created_by',
        'status',
    ];
}
