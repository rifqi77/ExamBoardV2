<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First forward migration after the baseline. `audit_logs` is owned solely by
 * the Laravel app (the Next/Prisma app doesn't use it), so a plain Blueprint
 * create is safe — it only adds a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('actor_id', 191)->nullable()->index();   // real actor (admin even when impersonating)
            $table->string('actor_name', 191)->nullable();
            $table->string('actor_role', 32)->nullable();
            $table->string('impersonated_id', 191)->nullable();     // teacher acted-as, if impersonating
            $table->string('action', 64)->index();                  // e.g. grade.set, submission.delete
            $table->string('target_type', 48)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->string('summary', 255)->nullable();
            $table->longText('meta')->nullable();                   // JSON context
            $table->string('ip', 64)->nullable();
            $table->dateTime('created_at', 3)->nullable()->index();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
