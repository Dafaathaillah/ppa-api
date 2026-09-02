<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleServer extends Model
{
    protected $fillable = [
        'id_scanner',
        'tanggal_inspection',
        'actual_inspection',
        'bulan',
        'tahun',
        'scanner_code',
        'location',
        'site',
    ];
}
