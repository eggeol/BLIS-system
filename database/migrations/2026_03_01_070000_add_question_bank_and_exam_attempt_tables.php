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
        if (!Schema::hasColumn('exams', 'question_bank_id')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->foreignId('question_bank_id')
                    ->nullable()
                    ->after('description')
                    ->constrained('question_banks')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('exam_attempts')) {
            Schema::create('exam_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
                $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('status', ['in_progress', 'submitted'])->default('in_progress');
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('duration_minutes')->default(0);
                $table->unsignedInteger('answered_count')->default(0);
                $table->unsignedInteger('correct_answers')->default(0);
                $table->decimal('score_percent', 5, 2)->nullable();
                $table->timestamp('started_at');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->index(['exam_id', 'room_id', 'user_id']);
                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('exam_attempt_questions')) {
            Schema::create('exam_attempt_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_attempt_id')
                    ->constrained('exam_attempts')
                    ->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')
                    ->constrained('question_bank_questions')
                    ->cascadeOnDelete();
                $table->unsignedInteger('item_number');
                $table->timestamps();

                $table->unique(['exam_attempt_id', 'question_bank_question_id'], 'attempt_question_unique');
                $table->unique(['exam_attempt_id', 'item_number'], 'attempt_item_number_unique');
            });
        }

        if (!Schema::hasTable('exam_attempt_answers')) {
            Schema::create('exam_attempt_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_attempt_id')
                    ->constrained('exam_attempts')
                    ->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')
                    ->constrained('question_bank_questions')
                    ->cascadeOnDelete();
                $table->foreignId('question_bank_option_id')
                    ->nullable()
                    ->constrained('question_bank_options')
                    ->nullOnDelete();
                $table->text('answer_text')->nullable();
                $table->boolean('is_correct')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();

                $table->unique(['exam_attempt_id', 'question_bank_question_id'], 'attempt_answer_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_answers');
        Schema::dropIfExists('exam_attempt_questions');
        Schema::dropIfExists('exam_attempts');

        if (Schema::hasColumn('exams', 'question_bank_id')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropConstrainedForeignId('question_bank_id');
            });
        }
    }
};
