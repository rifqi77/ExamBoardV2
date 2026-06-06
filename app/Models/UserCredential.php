<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCredential extends Model
{
    protected $table = 'user_credentials';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // password_set_at etc., no created/updated pair

    protected $guarded = [];

    protected $casts = [
        'failed_attempts' => 'integer',
        'password_set_at' => 'datetime',
        'last_sign_in_at' => 'datetime',
        'locked_until' => 'datetime',
    ];
}
