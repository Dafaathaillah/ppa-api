<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InspeksiCctvFinding extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'inspeksi_cctv_id',
        'cctv_id',
        'pica_number',
        'question_no',
        'question_name',
        'finding',
        'finding_photo',
        'due_date',
        'pic_name',
        'remark',
        'status',
        'action_taken',
        'action_photo',
        'closed_at',
    ];

    /**
     * Relationship back to the CCTV inspection
     */
    public function inspeksi()
    {
        return $this->belongsTo(InspeksiCctv::class, 'inspeksi_cctv_id');
    }

    /**
     * Optional relationship to the CCTV device itself
     */
    public function cctv()
    {
        return $this->belongsTo(InvCctv::class, 'cctv_id');
    }
}
