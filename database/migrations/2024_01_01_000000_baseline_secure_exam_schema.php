<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BASELINE / squash migration for the shared `secure_exam` database.
 *
 * This database originated from the Next.js app (Prisma). To bring it under
 * Laravel migrations WITHOUT endangering the live, shared data we capture the
 * exact current schema as `database/schema/baseline.sql` and replay it here.
 *
 *  - up() uses `CREATE TABLE IF NOT EXISTS`, so running it against the existing
 *    populated database is a harmless no-op — it only builds tables on a fresh
 *    database. On the live DB this migration is marked as already-applied
 *    (see database/MIGRATIONS.md) so it never executes there anyway.
 *  - Prisma's own `_prisma_migrations` table is intentionally excluded; it
 *    stays owned by the Next.js app.
 *
 * Future schema changes are normal, idiomatic Blueprint migrations added after
 * this one.
 */
return new class extends Migration
{
    /** Tables owned by this baseline (used by down()). */
    private array $tables = [
        'exam_token_redemptions', 'exam_submissions', 'answer_drafts', 'exam_media',
        'exam_questions', 'exam_sessions', 'exam_access_tokens', 'exam_generation_prompts',
        'class_students', 'student_classes', 'bank_questions', 'learning_objectives',
        'admin_upload_jobs', 'app_config_ai', 'exams', 'user_credentials', 'users',
    ];

    public function up(): void
    {
        $path = database_path('schema/baseline.sql');
        if (! is_file($path)) {
            throw new \RuntimeException("Baseline schema file missing: {$path}");
        }
        $sql = file_get_contents($path);
        // FK checks off so creation order between inter-dependent tables is moot.
        DB::unprepared("SET FOREIGN_KEY_CHECKS=0;\n".$sql."\nSET FOREIGN_KEY_CHECKS=1;");
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
