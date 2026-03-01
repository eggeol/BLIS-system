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
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'one_take_only')) {
                $table->boolean('one_take_only')
                    ->default(false)
                    ->after('scheduled_at');
            }

            if (!Schema::hasColumn('exams', 'shuffle_questions')) {
                $table->boolean('shuffle_questions')
                    ->default(false)
                    ->after('one_take_only');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'shuffle_questions')) {
                $table->dropColumn('shuffle_questions');
            }

            if (Schema::hasColumn('exams', 'one_take_only')) {
                $table->dropColumn('one_take_only');
            }
        });
    }
};
