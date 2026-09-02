<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordBank extends Model
{
    protected $fillable = [
        'title',
        'kategori',
        'deskripsi',
        'username',
        'password',
        'url',
        'remark',
        'nama_pembuat',
        'site',
        'role_owner',
        'user_id',
    ];
}


