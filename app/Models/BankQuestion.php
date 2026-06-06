<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankQuestion extends Model
{
    protected $table = 'bank_questions';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // created_at only

    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'options' => 'array',
        'correct_answer' => 'array',
        'points' => 'float',
        'created_at' => 'datetime',
    ];
}
