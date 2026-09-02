<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InspeksiNvrFinding extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'inspeksi_nvr_findings';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'inspeksi_nvr_id',
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

    public function inspeksiNvr()
    {
        return $this->belongsTo(InspeksiNvr::class, 'inspeksi_nvr_id', 'id');
    }
}
