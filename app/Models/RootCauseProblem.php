<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RootCauseProblem extends Model
{
    use HasFactory;

    protected $primaryKey = 'id_cause';

    public $timestamps = false;
}
