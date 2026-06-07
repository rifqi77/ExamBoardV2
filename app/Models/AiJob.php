<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiJob extends Model
{
    protected $table = 'ai_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'kind', 'status', 'progress', 'total', 'params', 'result', 'error',
    ];

    protected $casts = [
        'params' => 'array',
        'result' => 'array',
        'progress' => 'integer',
        'total' => 'integer',
    ];
}
