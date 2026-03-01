<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;

class ExamAttemptService
{
    public function __construct(
        private readonly ExamDeliveryModeService $deliveryModeService,
    ) {
    }

    public function refreshMetrics(ExamAttempt $attempt, bool $finalize = false): ExamAttempt
    {
        $answers = $attempt->answers()
            ->select('is_correct')
            ->get();

        $answeredCount = $answers->count();
        $correctAnswers = $answers->where('is_correct', true)->count();
        $scorePercent = $attempt->total_items > 0
            ? round(($correctAnswers / $attempt->total_items) * 100, 2)
            : 0.0;

        $attempt->answered_count = $answeredCount;
        $attempt->correct_answers = $correctAnswers;

        if ($finalize) {
            $attempt->status = ExamAttempt::STATUS_SUBMITTED;
            $attempt->submitted_at = $attempt->submitted_at ?? now();
            $attempt->score_percent = $scorePercent;
        }

        if ($attempt->status === ExamAttempt::STATUS_SUBMITTED && is_null($attempt->score_percent)) {
            $attempt->score_percent = $scorePercent;
        }

        $attempt->save();

        return $attempt->refresh();
    }

    public function autoSubmitExpiredAttempt(ExamAttempt $attempt): ExamAttempt
    {
        if (
            $attempt->status !== ExamAttempt::STATUS_IN_PROGRESS
            || is_null($attempt->expires_at)
            || now()->lessThanOrEqualTo($attempt->expires_at)
        ) {
            return $attempt;
        }

        return $this->refreshMetrics($attempt, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAttemptPayload(ExamAttempt $attempt): array
    {
        $attempt->loadMissing([
            'exam:id,title,subject,question_bank_id,total_items,duration_minutes,scheduled_at,delivery_mode,one_take_only,shuffle_questions',
            'room:id,name,code',
            'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
            'answers',
        ]);

        $deliveryMode = $this->deliveryModeService->normalize($attempt->exam?->delivery_mode);
        $isInstantFeedback = $deliveryMode === Exam::DELIVERY_MODE_INSTANT_FEEDBACK;
        $isSubmitted = $attempt->status === ExamAttempt::STATUS_SUBMITTED;
        $showPerQuestionFeedback = $isSubmitted || $isInstantFeedback;
        $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

        $teacherPacing = null;

        if ($deliveryMode === Exam::DELIVERY_MODE_TEACHER_PACED && $attempt->exam) {
            $teacherPacingState = $this->deliveryModeService->resolveTeacherPacingState((int) $attempt->exam_id, (int) $attempt->room_id);
            $teacherPacing = $this->deliveryModeService->buildTeacherPacingPayload(
                $teacherPacingState,
                $this->deliveryModeService->resolveTeacherPacedTotalItems($attempt->exam),
            );
        }

        $questions = $attempt->attemptQuestions
            ->sortBy('item_number')
            ->values()
            ->map(function (ExamAttemptQuestion $attemptQuestion) use (
                $answersByQuestionId,
                $isSubmitted,
                $isInstantFeedback,
                $showPerQuestionFeedback
            ): ?array {
                $question = $attemptQuestion->question;

                if (!$question) {
                    return null;
                }

                $answer = $answersByQuestionId->get($question->id);
                $hasAnswer = !is_null($answer);

                return [
                    'question_id' => $question->id,
                    'item_number' => $attemptQuestion->item_number,
                    'is_bookmarked' => (bool) $attemptQuestion->is_bookmarked,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'options' => $question->options->map(fn ($option) => [
                        'id' => $option->id,
                        'label' => $option->option_label,
                        'text' => $option->option_text,
                        'is_correct' => $isSubmitted ? (bool) $option->is_correct : null,
                    ])->values(),
                    'answer' => [
                        'selected_option_id' => $answer?->question_bank_option_id,
                        'answer_text' => $answer?->answer_text,
                        'is_correct' => $showPerQuestionFeedback && $hasAnswer ? $answer?->is_correct : null,
                    ],
                    'correct_answer' => ($isSubmitted || ($isInstantFeedback && $hasAnswer))
                        ? [
                            'label' => $question->answer_label,
                            'text' => $question->answer_text,
                        ]
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $remainingSeconds = null;

        if ($attempt->status === ExamAttempt::STATUS_IN_PROGRESS && $attempt->expires_at) {
            $remainingSeconds = max(0, now()->diffInSeconds($attempt->expires_at, false));
        }

        $nextRequiredItemNumber = null;

        if ($isInstantFeedback && !$isSubmitted) {
            $nextRequired = $attempt->attemptQuestions
                ->sortBy('item_number')
                ->first(fn (ExamAttemptQuestion $attemptQuestion) => !$answersByQuestionId->has((int) $attemptQuestion->question_bank_question_id));

            $nextRequiredItemNumber = $nextRequired?->item_number;
        }

        return [
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'total_items' => $attempt->total_items,
                'duration_minutes' => $attempt->duration_minutes,
                'answered_count' => $attempt->answered_count,
                'correct_answers' => $attempt->correct_answers,
                'score_percent' => $attempt->score_percent,
                'started_at' => $attempt->started_at,
                'expires_at' => $attempt->expires_at,
                'submitted_at' => $attempt->submitted_at,
                'remaining_seconds' => $remainingSeconds,
                'next_required_item_number' => $nextRequiredItemNumber,
            ],
            'exam' => [
                'id' => $attempt->exam?->id ?? $attempt->exam_id,
                'title' => $attempt->exam?->title,
                'subject' => $attempt->exam?->subject,
                'question_bank_id' => $attempt->exam?->question_bank_id,
                'total_items' => $attempt->exam?->total_items,
                'duration_minutes' => $attempt->exam?->duration_minutes,
                'scheduled_at' => $attempt->exam?->scheduled_at,
                'delivery_mode' => $deliveryMode,
                'one_take_only' => (bool) ($attempt->exam?->one_take_only ?? false),
                'shuffle_questions' => (bool) ($attempt->exam?->shuffle_questions ?? false),
            ],
            'room' => [
                'id' => $attempt->room?->id ?? $attempt->room_id,
                'name' => $attempt->room?->name,
                'code' => $attempt->room?->code,
            ],
            'teacher_pacing' => $teacherPacing,
            'questions' => $questions,
        ];
    }
}

