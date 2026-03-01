<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('exams', 'delivery_mode')) {
            DB::statement(
                "ALTER TABLE exams MODIFY COLUMN delivery_mode ENUM(
                    'open_navigation',
                    'teacher_paced',
                    'instant_feedback',
                    'standard',
                    'live_quiz'
                ) NOT NULL DEFAULT 'open_navigation'"
            );
        }

        if (!Schema::hasTable('exam_room_pacing_states')) {
            Schema::create('exam_room_pacing_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_id')
                    ->constrained('exams')
                    ->cascadeOnDelete();
                $table->foreignId('room_id')
                    ->constrained('rooms')
                    ->cascadeOnDelete();
                $table->boolean('is_active')->default(false);
                $table->unsignedInteger('current_item_number')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['exam_id', 'room_id'], 'exam_room_pacing_unique');
                $table->index(['exam_id', 'room_id', 'is_active'], 'exam_room_pacing_active_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_room_pacing_states');

        if (Schema::hasColumn('exams', 'delivery_mode')) {
            DB::statement(
                "UPDATE exams
                 SET delivery_mode = CASE
                    WHEN delivery_mode = 'teacher_paced' THEN 'live_quiz'
                    ELSE 'standard'
                 END"
            );

            DB::statement(
                "ALTER TABLE exams MODIFY COLUMN delivery_mode ENUM(
                    'standard',
                    'live_quiz'
                ) NOT NULL DEFAULT 'standard'"
            );
        }
    }
};
