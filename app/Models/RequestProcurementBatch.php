<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestProcurementBatch extends Model
{
    use HasUuids;

    protected $fillable = ['batch_number', 'site', 'exported_by', 'exported_name', 'exported_at', 'status'];
    protected $casts = ['exported_at' => 'datetime'];
    public function items() { return $this->belongsToMany(RequestInternalItem::class, 'request_procurement_batch_items')->withTimestamps(); }
}
