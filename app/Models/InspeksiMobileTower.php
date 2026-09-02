<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class InspeksiMobileTower extends Model
{
    use HasFactory;

    // Primary key is UUID
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'inv_mt_id',
        'pica_number',
        'created_date',
        'worthiness',
        'condition',
        'inspection_at',
        'month',
        'year',
        'inspection_status',
        'device_status',
        'checklist_results_list',
        'list_results_remark',
        'findings',
        'findings_image',
        'findings_status',
        'findings_action',
        'action_image',
        'inspection_image',
        'due_date',
        'remarks',
        'inspector',
        'pic',
        'crew',
        'list_of_needs',
        'approved_by',
        'status_approval',
        'site'
    ];


    /**
     * Boot function to auto-generate UUID when creating
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Relation: An inspection belongs to a mobile tower
     */
    public function mt()
    {
        return $this->belongsTo(InvMobileTower::class, 'inv_mt_id', 'id');
    }

    public function picas()
    {
        return $this->hasMany(
            PicaInspeksi::class,
            'inspeksi_id',
            'id'
        );
    }

    public function devices()
    {
        return $this->hasMany(
            InspeksiMobileTowerDevice::class,
            'inspeksi_mobile_tower_id',
            'id'
        );
    }
}
