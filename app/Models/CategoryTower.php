<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CategoryTower extends Model
{
    use HasUuids;

    protected $table = 'category_tower';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'category_code',
        'category_name',
        'is_active',
    ];
}
