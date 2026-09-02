<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvPrinter extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'max_id',
        'item_name',
        'printer_code',
        'asset_ho_number',
        'serial_number',
        'ip_address',
        'mac_address',
        'printer_brand',
        'printer_type',
        'division',
        'department',
        'location',
        'date_of_inventory',
        'status',
        'note',
        'site'
    ];

        public function inspeksi()
    {
        return $this->hasMany(InspeksiPrinter::class, 'inv_printer_id', 'id');
    }
}
