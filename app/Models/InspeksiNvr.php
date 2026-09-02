<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InspeksiNvr extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'nvr_id',
        'pica_number',
        'inspection_month',
        'inspection_year',
        'inspection_date',
        'inspector_name',
        'q1_accessible',
        'q2_time_sync',
        'q3_hdd_detected',
        'q4_hdd_normal',
        'q5_recording_active',
        'q6_playback_ok',
        'remark',
        'kondisi',
        'kelayakan',
        'inv_status',
        'status',
        'site',
        'approved_by',
        'status_approval'
    ];

    public function invNvr()
    {
        return $this->belongsTo(InvNvr::class, 'nvr_id', 'id');
    }

    public function findings()
    {
        return $this->hasMany(InspeksiNvrFinding::class, 'inspeksi_nvr_id', 'id');
    }
}
