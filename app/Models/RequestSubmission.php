<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RequestSubmission extends Model
{
    use HasUuids;

    protected $fillable = [

        'request_number',

        'request_type',

        'created_by',
        'created_name',
        'created_site',

        'request_by',
        'request_name',
        'request_department',
        'request_position',
        'request_site',
        'request_email',
        'requester_rkb_number',
        'ict_rkb_number',

        'status',

        'current_step',

        'submitted_at',
        'submitted_by',
        'submitted_name',
        'operational_status',
        'operational_updated_at',
        'closed_at'

    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'operational_updated_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const HO_OPERATIONAL_TYPES = ['EMAIL', 'ACCESS', 'VPN', 'HO_ACCESS'];
    public const INTERNAL_SITE_TYPES = ['NETWORK', 'SOFTWARE', 'OTHER'];

    public function requiresHoOperationalAction(): bool
    {
        return in_array(strtoupper((string) $this->request_type), self::HO_OPERATIONAL_TYPES, true);
    }

    public function isInternalSiteRequest(): bool
    {
        return in_array(strtoupper((string) $this->request_type), self::INTERNAL_SITE_TYPES, true);
    }

    public function getEffectiveRkbNumberAttribute(): ?string
    {
        return $this->ict_rkb_number ?: $this->requester_rkb_number;
    }

    public function isFullyApproved(): bool
    {
        $approvals = $this->relationLoaded('approvals') ? $this->approvals : $this->approvals()->get();

        return $approvals->isNotEmpty()
            && $approvals->every(fn ($approval) => $approval->status === 'APPROVED');
    }

    public function hardware()
    {
        return $this->hasOne(
            RequestHardware::class,
            'request_submission_id'
        );
    }

    public function emailRequest()
    {
        return $this->hasOne(
            RequestEmail::class,
            'request_submission_id'
        );
    }

    public function accessRequest()
    {
        return $this->hasOne(RequestAccess::class, 'request_submission_id');
    }

    public function networkRequest()
    {
        return $this->hasOne(RequestNetwork::class, 'request_submission_id');
    }

    public function internalItems()
    {
        return $this->hasMany(RequestInternalItem::class, 'request_submission_id');
    }
    
    public function attachments()
    {
        return $this->hasMany(
            RequestAttachment::class,
            'request_submission_id'
        );
    }

    public function histories()
    {
        return $this->hasMany(
            RequestHistory::class,
            'request_submission_id'
        )->latest();
    }

    public function approvals()
    {
        return $this->hasMany(
            RequestApproval::class,
            'request_submission_id'
        )->orderBy('step');
    }

    public function hardwareReview()
    {
        return $this->hasOne(
            RequestHardwareReview::class,
            'request_submission_id'
        );
    }

    public function approvalTokens()
    {
        return $this->hasMany(
            RequestApprovalToken::class,
            'request_submission_id'
        );
    }

    public function hoFeedback()
    {
        return $this->hasOne(RequestHoFeedback::class, 'request_submission_id');
    }
}
