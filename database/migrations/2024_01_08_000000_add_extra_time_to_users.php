<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-student extra-time accommodation, as a percentage of the exam duration
 * (e.g. 25 or 50). Default 0 = no accommodation, so existing students and the
 * exam timer are unaffected. Safe to run on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'extra_time_percent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('extra_time_percent')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'extra_time_percent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('extra_time_percent');
            });
        }
    }
};
