<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    protected $table = 'exam_sessions';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // started_at / last_saved_at managed explicitly

    protected $guarded = [];

    protected $casts = [
        'attempt' => 'integer',
        'time_used_seconds' => 'integer',
        'anti_cheat_events' => 'array',
        'drawn_question_ids' => 'array',
        'started_at' => 'datetime',
        'last_saved_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function drafts(): HasMany
    {
        return $this->hasMany(AnswerDraft::class, 'session_id', 'id');
    }
}
