<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordCategory extends Model
{
    use HasFactory;

    protected $table = 'password_categories';

    protected $fillable = [
        'name',
    ];
}
