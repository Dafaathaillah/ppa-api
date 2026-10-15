<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InspeksiPrinter extends Model
{
    use HasFactory, HasUuids;

    // Primary key is UUID
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'inv_printer_id',
        'pica_number',
        'created_date',
        'inspection_at',
        'condition',
        'month',
        'year',
        'inspection_status',
        'inspector',
        'tinta_cyan',
        'tinta_magenta',
        'tinta_yellow',
        'tinta_black',
        'body_condition',
        'usb_cable_condition',
        'power_cable_condition',
        'performing_physical_power_cleaning',
        'performing_cleaning_on_the_printer_waste_box',
        'performing_cleaning_head',
        'performing_print_quality_test',
        'do_replacing_cable',
        'findings',
        'findings_image',
        'findings_action',
        'action_image',
        'findings_status',
        'remarks',
        'inventory_status',
        'due_date',
        'approved_by',
        'status_approval',
        'inspection_image',
        'nozle_image',
        'site',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
    
    public function printer()
    {
        return $this->belongsTo(InvPrinter::class, 'inv_printer_id', 'id');
    }
}
