<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransaction extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = [
        'STOCK_IN',
        'STOCK_OUT',
        'ADJUSTMENT',
        'TRANSFER',
        'TRANSFER_OUT',
        'TRANSFER_IN',
        'RETURN',
    ];

    protected $table = 'stock_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'stock_id',
        'site',
        'transaction_no',
        'transaction_type',
        'qty',
        'stock_before',
        'stock_after',
        'reference_no',
        'from_site',
        'to_site',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    public function stock()
    {
        return $this->belongsTo(
            Stock::class,
            'stock_id',
            'id'
        );
    }
}
