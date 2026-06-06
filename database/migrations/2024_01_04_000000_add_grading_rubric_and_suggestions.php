<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-assisted grading support (additive, nullable → existing rows unchanged).
 *   - exam_questions.rubric: optional [{criterion, points}] for essay items,
 *     fed to the AI grader and shown to the teacher.
 *   - exam_submissions.grading_suggestions: persisted AI draft scores per
 *     question, so the chi-square check can compare them to the teacher's
 *     final manual_scores later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', function (Blueprint $t) {
            if (! Schema::hasColumn('exam_questions', 'rubric')) {
                $t->json('rubric')->nullable();
            }
        });
        Schema::table('exam_submissions', function (Blueprint $t) {
            if (! Schema::hasColumn('exam_submissions', 'grading_suggestions')) {
                $t->json('grading_suggestions')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_questions', function (Blueprint $t) {
            if (Schema::hasColumn('exam_questions', 'rubric')) {
                $t->dropColumn('rubric');
            }
        });
        Schema::table('exam_submissions', function (Blueprint $t) {
            if (Schema::hasColumn('exam_submissions', 'grading_suggestions')) {
                $t->dropColumn('grading_suggestions');
            }
        });
    }
};
