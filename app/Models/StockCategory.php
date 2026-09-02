<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCategory extends Model
{
    protected $table = 'stock_categories';

    protected $fillable = [
        'category_code',
        'category_name',
        'is_active',
    ];

    public function stocks()
    {
        return $this->hasMany(
            Stock::class,
            'stock_category_id'
        );
    }
}
