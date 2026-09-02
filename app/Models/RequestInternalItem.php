<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestInternalItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'request_submission_id', 'requester_nrp', 'requester_name',
        'requester_department', 'requester_email', 'item_name', 'item_type', 'quantity', 'unit',
        'description', 'reference_link', 'image_path', 'operational_status',
        'operational_updated_at', 'closed_at', 'exported_at',
    ];

    protected $casts = [
        'operational_updated_at' => 'datetime', 'closed_at' => 'datetime',
        'exported_at' => 'datetime', 'quantity' => 'integer',
    ];

    public function submission() { return $this->belongsTo(RequestSubmission::class, 'request_submission_id'); }
    public function batches() { return $this->belongsToMany(RequestProcurementBatch::class, 'request_procurement_batch_items')->withTimestamps(); }
}
