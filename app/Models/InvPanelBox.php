<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvPanelBox extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'item_name',
        'max_id',
        'inventory_number',
        'asset_ho_number',
        'category_panel_box',
        'panel_type',
        'location',
        'note',
        'status',
        'inspection_remark',
        'wired_diagram',
        'site',
    ];
}
