<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnswerDraft extends Model
{
    protected $table = 'answer_drafts';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // updated_at managed explicitly in upsert

    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
        'updated_at' => 'datetime',
    ];
}
