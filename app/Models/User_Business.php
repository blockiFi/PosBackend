<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User_Business extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
    ];
}
