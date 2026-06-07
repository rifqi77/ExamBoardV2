<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks long-running AI work (question generation, grade suggestions) so the
 * web request can return immediately and the SPA can poll for progress/result.
 * Guarded for safe re-run on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_jobs')) {
            return;
        }
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->string('kind', 40);                 // generate_questions | suggest_grades
            $table->string('status', 20)->default('queued'); // queued|running|done|failed
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->json('params')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
