<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PengalihanAsset extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'id_inventory',
        'id_inv_prev',
        'nrp_user_prev',
        'nrp_user_new',
        'inv_number_next',
        'tanggal_pengalihan',
        'foto_pengalihan',
        'remark',
        'device',
        'site',
        'dept',
        'dept_prev',
    ];

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(UserAll::class, 'user_alls_id', 'id');
    }

    public function laptopPrev()
    {
        return $this->belongsTo(InvLaptop::class, 'inv_laptop_id', 'id');
    }

        public function laptopNext()
    {
        return $this->belongsTo(InvLaptop::class, 'inv_laptop_id', 'id');
    }

    public function computerPrev()
    {
        return $this->belongsTo(InvComputer::class, 'inv_laptop_id', 'id');
    }

        public function computerNext()
    {
        return $this->belongsTo(InvComputer::class, 'inv_laptop_id', 'id');
    }
}
