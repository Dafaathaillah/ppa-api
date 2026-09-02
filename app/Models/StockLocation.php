<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLocation extends Model
{
    protected $fillable = [
        'site',
        'location_code',
        'location_name',
        'is_active',
    ];

    public function stocks()
    {
        return $this->hasMany(
            Stock::class,
            'stock_location_id'
        );
    }
}
