<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class NvrCctvViewOnly extends Pivot
{
    protected $table = 'nvr_cctv_view_only';
    protected $fillable = ['nvr_id', 'cctv_id'];
}
