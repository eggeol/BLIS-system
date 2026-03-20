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
        if (!Schema::hasTable('exam_question_bank')) {
            Schema::create('exam_question_bank', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
                $table->foreignId('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
                $table->unsignedInteger('position')->default(1);
                $table->timestamps();

                $table->unique(['exam_id', 'question_bank_id'], 'exam_question_bank_unique');
                $table->unique(['exam_id', 'position'], 'exam_question_bank_position_unique');
            });
        }

        $now = now();

        $legacyLinks = DB::table('exams')
            ->select('id as exam_id', 'question_bank_id')
            ->whereNotNull('question_bank_id')
            ->get()
            ->map(fn ($exam) => [
                'exam_id' => (int) $exam->exam_id,
                'question_bank_id' => (int) $exam->question_bank_id,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($legacyLinks !== []) {
            DB::table('exam_question_bank')->insertOrIgnore($legacyLinks);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_question_bank');
    }
};
