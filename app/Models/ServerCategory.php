<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServerCategory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'server_categories';


    protected $keyType = 'string';

    public $incrementing = false;

     public function servers()
    {
        return $this->hasMany(
            InvServer::class,
            'category_id',
            'id'
        );
    }
}
