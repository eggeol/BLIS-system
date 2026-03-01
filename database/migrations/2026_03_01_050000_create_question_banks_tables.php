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
        if (!Schema::hasTable('question_banks')) {
            Schema::create('question_banks', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('subject')->nullable();
                $table->string('source_filename')->nullable();
                $table->unsignedInteger('total_items')->default(0);
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('question_bank_questions')) {
            Schema::create('question_bank_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('question_bank_id')
                    ->constrained('question_banks')
                    ->cascadeOnDelete();
                $table->unsignedInteger('item_number');
                $table->text('question_text');
                $table->enum('question_type', ['multiple_choice', 'true_false', 'open_ended'])
                    ->default('multiple_choice');
                $table->string('answer_label', 2)->nullable();
                $table->text('answer_text')->nullable();
                $table->timestamps();

                $table->unique(['question_bank_id', 'item_number'], 'qb_questions_item_unique');
            });
        }

        if (!Schema::hasTable('question_bank_options')) {
            Schema::create('question_bank_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('question_bank_question_id')
                    ->constrained('question_bank_questions')
                    ->cascadeOnDelete();
                $table->unsignedTinyInteger('sort_order')->default(1);
                $table->string('option_label', 2);
                $table->text('option_text');
                $table->boolean('is_correct')->default(false);
                $table->timestamps();

                $table->unique(['question_bank_question_id', 'option_label'], 'qb_options_label_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_bank_options');
        Schema::dropIfExists('question_bank_questions');
        Schema::dropIfExists('question_banks');
    }
};
