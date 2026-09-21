<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListUnit extends Model
{
    protected $table = 'list_unit';

    public $timestamps = false;

    protected $fillable = [
        'type',
        'model',
        'sn',
        'cn',
        'site',
        'status',
        'tipe_wirelless',
        'ip_wirelless',
        'mac_wirelless',
        'modem_teltonika',
        'status_vhms',
        'last_download',
        'last_operation',
        'remark',
        'last_update_at',
        'last_update_by',
    ];

    public function repairs(): HasMany
    {
        return $this->hasMany(UnitVhmsRepair::class, 'list_unit_id')
            ->latest('created_at')
            ->latest('id');
    }

    public function notUpdateCases(): HasMany
    {
        return $this->hasMany(VhmsNotUpdateCase::class, 'list_unit_id');
    }

    public function conditionPeriods(): HasMany
    {
        return $this->hasMany(VhmsUnitConditionPeriod::class, 'list_unit_id');
    }
}
