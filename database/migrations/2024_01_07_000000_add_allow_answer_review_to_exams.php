<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teacher-controlled toggle: may students review their questions, correct
 * answers, and the mark scheme after submitting? Off by default so answers
 * are never leaked during an open exam window. Safe to run on the live DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exams', 'allow_answer_review')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->boolean('allow_answer_review')->default(false)->after('shuffle_options');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exams', 'allow_answer_review')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropColumn('allow_answer_review');
            });
        }
    }
};
