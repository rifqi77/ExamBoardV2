<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model
{
    protected $table = 'exam_questions';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // created_at only

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'points' => 'float',
        'tags' => 'array',
        'options' => 'array',
        'correct_answer' => 'array',
        'explanation_media' => 'array',
    ];
}
