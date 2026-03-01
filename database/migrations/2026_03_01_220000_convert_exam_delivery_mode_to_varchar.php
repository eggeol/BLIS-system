<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('exams', 'delivery_mode')) {
            return;
        }

        DB::statement(
            "UPDATE exams
             SET delivery_mode = CASE
                WHEN delivery_mode = 'live_quiz' THEN 'teacher_paced'
                WHEN delivery_mode = 'standard' THEN 'open_navigation'
                ELSE delivery_mode
             END"
        );

        DB::statement(
            "ALTER TABLE exams
             MODIFY COLUMN delivery_mode VARCHAR(32) NOT NULL DEFAULT 'open_navigation'"
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('exams', 'delivery_mode')) {
            return;
        }

        DB::statement(
            "ALTER TABLE exams
             MODIFY COLUMN delivery_mode ENUM(
                'open_navigation',
                'teacher_paced',
                'instant_feedback',
                'standard',
                'live_quiz'
             ) NOT NULL DEFAULT 'open_navigation'"
        );
    }
};

