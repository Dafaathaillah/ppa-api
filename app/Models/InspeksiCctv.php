<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InspeksiCctv extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'inspeksi_cctvs';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'cctv_id',
        'inspeksi_nvr_id',
        'inspection_month',
        'inspection_year',
        'inspection_date',
        'inspector_name',
        'q1_online',
        'q2_clear',
        'q3_precise',
        'q4_recording',
        'remark',
        'kondisi',
        'kelayakan',
        'inv_status',
        'status',
        'site',
        'approved_by',
        'status_approval'
    ];

    public function findings()
    {
        return $this->hasMany(InspeksiCctvFinding::class, 'inspeksi_cctv_id', 'id');
    }

    public function nvr()
    {
        return $this->belongsTo(InvNvr::class, 'nvr_id', 'id');
    }

    public function cctv()
    {
        return $this->belongsTo(InvCctv::class, 'cctv_id', 'id');
    }
}
