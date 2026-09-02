<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InvMobileTower extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_number',
        'mt_code',
        'type_mt',
        'location',
        'detail_location',
        'gps',
        'led_lamp',
        'condition',
        'status',
        'note',
        'padlock_code',
        'site',
        'inspection_remark'
    ];

    // UUID settings
    protected $keyType = 'string';
    public $incrementing = false;

    // Auto-generate UUID on create
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

        public function inspeksi()
    {
        return $this->hasMany(InspeksiMobileTower::class, 'inv_mt_id', 'id');
    }
}
