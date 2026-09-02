<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Stock extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'stocks';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'site',
        'item_code',
        'item_name',
        'type',
        'stock_category_id',
        'stock_location_id',
        'brand',
        'current_qty',
        'min_qty',
        'uom',
        'description',
        'is_active',
    ];

    public function transactions()
    {
        return $this->hasMany(
            StockTransaction::class,
            'stock_id',
            'id'
        );
    }

    public function category()
    {
        return $this->belongsTo(
            StockCategory::class,
            'stock_category_id'
        );
    }

    public function location()
    {
        return $this->belongsTo(
            StockLocation::class,
            'stock_location_id'
        );
    }
}
