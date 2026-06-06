<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-student randomized question draw (opt-in).
 *   - exams.draw_count: if set (< pool size), each student is served that many
 *     questions sampled from the exam's pool (defeats answer-sharing).
 *   - exam_sessions.drawn_question_ids: the exact subset drawn for a session,
 *     snapshotted at session creation so it's stable across refresh/resume and
 *     unaffected by later edits to the pool.
 * Both nullable → existing exams are completely unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $t) {
            if (! Schema::hasColumn('exams', 'draw_count')) {
                $t->unsignedSmallInteger('draw_count')->nullable();
            }
        });
        Schema::table('exam_sessions', function (Blueprint $t) {
            if (! Schema::hasColumn('exam_sessions', 'drawn_question_ids')) {
                $t->json('drawn_question_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $t) {
            if (Schema::hasColumn('exams', 'draw_count')) {
                $t->dropColumn('draw_count');
            }
        });
        Schema::table('exam_sessions', function (Blueprint $t) {
            if (Schema::hasColumn('exam_sessions', 'drawn_question_ids')) {
                $t->dropColumn('drawn_question_ids');
            }
        });
    }
};
