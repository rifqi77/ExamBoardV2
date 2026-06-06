<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Maps the existing Prisma-managed `users` table (string UUID PK,
 * snake_case columns). Read/written by the Laravel port; the schema is
 * owned by the Next.js/Prisma app for now.
 */
class User extends Model
{
    protected $table = 'users';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true; // has created_at + updated_at

    protected $guarded = [];

    protected $hidden = ['capabilities'];

    protected $casts = [
        'active' => 'boolean',
        'capabilities' => 'array',
        'token_version' => 'integer',
    ];

    public function credential(): HasOne
    {
        return $this->hasOne(UserCredential::class, 'user_id', 'id');
    }
}
