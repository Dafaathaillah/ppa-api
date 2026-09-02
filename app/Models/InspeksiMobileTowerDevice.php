<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class InspeksiMobileTowerDevice extends Model
{
    use HasFactory;

    protected $table = 'inspeksi_mobile_tower_devices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [

        'inspeksi_mobile_tower_id',

        'device_type',

        'device_inspection_id',

        'inventory_number',

        'month',

        'year',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {

            if (!$model->id) {

                $model->id = (string) Str::uuid();
            }
        });
    }
}