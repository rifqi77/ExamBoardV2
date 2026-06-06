<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSubmission extends Model
{
    protected $table = 'exam_submissions';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // submitted_at / graded_at

    protected $guarded = [];

    protected $casts = [
        'final_score' => 'float',
        'possible_score' => 'float',
        'percent_score' => 'float',
        'passed' => 'boolean',
        'pending_essay_count' => 'integer',
        'topic_breakdown' => 'array',
        'answers_snapshot' => 'array',
        'manual_scores' => 'array',
        'anti_cheat_events' => 'array',
        'review_items' => 'array',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
    ];
}
