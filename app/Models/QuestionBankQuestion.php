<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBankQuestion extends Model
{
    use HasFactory;

    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    public const TYPE_TRUE_FALSE = 'true_false';
    public const TYPE_OPEN_ENDED = 'open_ended';

    public const TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TRUE_FALSE,
        self::TYPE_OPEN_ENDED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'question_bank_id',
        'item_number',
        'question_text',
        'question_type',
        'answer_label',
        'answer_text',
    ];

    /**
     * Parent question bank.
     */
    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class);
    }

    /**
     * Answer options for the question.
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionBankOption::class)
            ->orderBy('sort_order');
    }

    /**
     * Attempt-question slots that reference this question.
     */
    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class, 'question_bank_question_id');
    }

    /**
     * Answers submitted against this question.
     */
    public function attemptAnswers(): HasMany
    {
        return $this->hasMany(ExamAttemptAnswer::class, 'question_bank_question_id');
    }
}
