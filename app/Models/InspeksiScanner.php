<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspeksiScanner extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'inspeksi_scanners';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'inv_scanner_id',
        'pica_number',
        'created_date',
        'condition',
        'inspection_at',
        'month',
        'year',
        'inspection_status',
        'last_edited_by',
        'inspector',
        'fisik',
        'kaca',
        'kabel',
        'power',
        'mekanik',
        'hasil_scan',
        'findings',
        'findings_image',
        'findings_action',
        'action_image',
        'findings_status',
        'remark',
        'due_date',
        'inventory_status',
        'approved_by',
        'status_approval',
        'inspection_image',
        'site',
    ];

    public function Scanner()
    {
        return $this->belongsTo(
            InvScanner::class,
            'inv_scanner_id',
            'id'
        );
    }
}
