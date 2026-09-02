<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspeksiPanelBoxDevice extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'inspeksi_panel_box_devices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'inspeksi_panel_box_id',
        'device_type',
        'device_inspection_id',
        'inventory_number',
        'month',
        'year',
    ];

    public function panelbox()
    {
        return $this->belongsTo(
            InspeksiPanelBoxNetwork::class,
            'inspeksi_panel_box_id'
        );
    }
}
