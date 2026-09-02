<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleScanner extends Model
{
    protected $table = 'schedule_scanners';

    protected $fillable = [
        'id_scanner',
        'tanggal_inspection',
        'actual_inspection',
        'bulan',
        'tahun',
        'scanner_code',
        'dept',
        'site',
    ];
}
