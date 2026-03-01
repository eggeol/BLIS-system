<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('exams', 'status')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        if (!Schema::hasColumn('users', 'student_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('student_id', 32)->nullable()->after('email');
                $table->unique('student_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'student_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['student_id']);
                $table->dropColumn('student_id');
            });
        }

        if (!Schema::hasColumn('exams', 'status')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->enum('status', ['draft', 'published', 'archived'])
                    ->default('draft')
                    ->after('duration_minutes');
            });
        }
    }
};
