<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvTower extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'tower_code',
        'category_id',
        'location',
        'detail_location',
        'type_tower',
        'status',
        'note',
        'remark_inspeksi',
        'site',
    ];
    public function category()
    {
        return $this->belongsTo(CategoryTower::class, 'category_id', 'id');
    }
}
