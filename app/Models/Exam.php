<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $table = 'exams';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'seb_required' => 'boolean',
        'allow_answer_review' => 'boolean',
        'draw_count' => 'integer',
        'type_distribution' => 'array',
        'difficulty_distribution' => 'array',
        'media_targets' => 'array',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'exam_id', 'id');
    }
}
