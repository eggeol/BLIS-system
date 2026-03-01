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
        if (!Schema::hasColumn('exam_attempt_questions', 'is_bookmarked')) {
            Schema::table('exam_attempt_questions', function (Blueprint $table) {
                $table->boolean('is_bookmarked')
                    ->default(false)
                    ->after('item_number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('exam_attempt_questions', 'is_bookmarked')) {
            Schema::table('exam_attempt_questions', function (Blueprint $table) {
                $table->dropColumn('is_bookmarked');
            });
        }
    }
};
