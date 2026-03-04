<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;

class ReportExportService
{
    /**
     * Build session-level results using the latest attempt per student.
     *
     * @return array<string, mixed>
     */
    public function buildSessionLatestResults(Exam $exam, Room $room): array
    {
        $students = $room->members()
            ->select('users.id', 'users.name', 'users.email', 'users.student_id', 'users.role')
            ->where('users.role', User::ROLE_STUDENT)
            ->orderBy('users.name')
            ->get();

        $studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();

        $attempts = ExamAttempt::query()
            ->where('exam_id', (int) $exam->id)
            ->where('room_id', (int) $room->id)
            ->when(
                count($studentIds) > 0,
                fn ($query) => $query->whereIn('user_id', $studentIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->with([
                'student:id,name,email,student_id',
                'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
                'attemptQuestions.question:id,question_text,question_type,answer_label,answer_text',
                'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
                'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
                'answers.selectedOption:id,option_label,option_text,is_correct',
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        $latestAttemptsByStudentId = $attempts
            ->groupBy('user_id')
            ->map(fn (Collection $group) => $group->first());

        $maxItemsFromAttempts = $latestAttemptsByStudentId
            ->map(function (ExamAttempt $attempt): int {
                $maxItemNumber = (int) ($attempt->attemptQuestions->max('item_number') ?? 0);

                return max((int) $attempt->total_items, $maxItemNumber);
            })
            ->max() ?? 0;

        $maxItems = max(1, (int) $exam->total_items, (int) $maxItemsFromAttempts);

        $itemStats = [];
        for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
            $itemStats[$itemNumber] = [
                'started_count' => 0,
                'answered_count' => 0,
                'correct_count' => 0,
            ];
        }

        $itemQuestionTexts = [];
        $shortAnswerDump = [];

        $rows = $students->map(function (User $student) use (
            $latestAttemptsByStudentId,
            $maxItems,
            &$itemStats,
            &$itemQuestionTexts,
            &$shortAnswerDump
        ): array {
            /** @var ExamAttempt|null $attempt */
            $attempt = $latestAttemptsByStudentId->get((int) $student->id);
            $itemPayloadByNumber = [];

            if ($attempt) {
                $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

                foreach ($attempt->attemptQuestions->sortBy('item_number')->values() as $attemptQuestion) {
                    /** @var ExamAttemptQuestion $attemptQuestion */
                    $itemNumber = (int) $attemptQuestion->item_number;
                    $question = $attemptQuestion->question;
                    $questionId = (int) $attemptQuestion->question_bank_question_id;

                    /** @var ExamAttemptAnswer|null $answer */
                    $answer = $answersByQuestionId->get($questionId);
                    $isAnswered = !is_null($answer);

                    $isCorrect = null;
                    if ($isAnswered && !is_null($answer?->is_correct)) {
                        $isCorrect = (bool) $answer->is_correct;
                    }

                    $responseText = $this->formatResponseText($answer);

                    $questionText = trim((string) ($question?->question_text ?? ''));
                    if ($questionText !== '' && !array_key_exists($itemNumber, $itemQuestionTexts)) {
                        $itemQuestionTexts[$itemNumber] = $questionText;
                    }

                    if (
                        $question?->question_type === QuestionBankQuestion::TYPE_OPEN_ENDED
                        && $responseText !== null
                    ) {
                        if (!array_key_exists($itemNumber, $shortAnswerDump)) {
                            $shortAnswerDump[$itemNumber] = [
                                'item_number' => $itemNumber,
                                'question_text' => $questionText !== '' ? $questionText : ('Item ' . $itemNumber),
                                'responses' => [],
                            ];
                        }

                        $shortAnswerDump[$itemNumber]['responses'][] = [
                            'student_name' => $student->name,
                            'student_id' => $student->student_id,
                            'answer_text' => $responseText,
                            'is_correct' => $isCorrect,
                        ];
                    }

                    $statusLabel = 'No Answer';
                    if ($isAnswered && $isCorrect === true) {
                        $statusLabel = 'Correct';
                    } elseif ($isAnswered && $isCorrect === false) {
                        $statusLabel = 'Wrong';
                    } elseif ($isAnswered) {
                        $statusLabel = 'Answered';
                    }

                    $itemPayloadByNumber[$itemNumber] = [
                        'item_number' => $itemNumber,
                        'question_id' => $questionId,
                        'question_type' => $question?->question_type,
                        'question_text' => $questionText !== '' ? $questionText : null,
                        'answered' => $isAnswered,
                        'is_correct' => $isCorrect,
                        'response' => $responseText,
                        'status_label' => $statusLabel,
                    ];
                }

                $attemptItemCap = max(
                    (int) $attempt->total_items,
                    empty($itemPayloadByNumber) ? 0 : (int) max(array_keys($itemPayloadByNumber)),
                );

                for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
                    if ($itemNumber > $attemptItemCap) {
                        continue;
                    }

                    $itemStats[$itemNumber]['started_count']++;

                    $itemData = $itemPayloadByNumber[$itemNumber] ?? null;
                    if (!($itemData['answered'] ?? false)) {
                        continue;
                    }

                    $itemStats[$itemNumber]['answered_count']++;

                    if (($itemData['is_correct'] ?? null) === true) {
                        $itemStats[$itemNumber]['correct_count']++;
                    }
                }
            }

            $items = [];
            $itemStatusLabels = [];

            for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
                $item = $itemPayloadByNumber[$itemNumber] ?? [
                    'item_number' => $itemNumber,
                    'question_id' => null,
                    'question_type' => null,
                    'question_text' => null,
                    'answered' => false,
                    'is_correct' => null,
                    'response' => null,
                    'status_label' => 'No Answer',
                ];

                $items[] = $item;
                $itemStatusLabels[$itemNumber] = (string) $item['status_label'];
            }

            return [
                'student' => [
                    'id' => (int) $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'student_id' => $student->student_id,
                ],
                'attempt' => $attempt
                    ? [
                        'id' => (int) $attempt->id,
                        'status' => $attempt->status,
                        'total_items' => (int) $attempt->total_items,
                        'answered_count' => (int) $attempt->answered_count,
                        'correct_answers' => (int) $attempt->correct_answers,
                        'score_percent' => is_null($attempt->score_percent) ? null : (float) $attempt->score_percent,
                        'started_at' => $attempt->started_at,
                        'submitted_at' => $attempt->submitted_at,
                    ]
                    : null,
                'items' => $items,
                'item_status_labels' => $itemStatusLabels,
            ];
        })->values()->all();

        $itemSummary = [];
        for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
            $startedCount = (int) $itemStats[$itemNumber]['started_count'];
            $answeredCount = (int) $itemStats[$itemNumber]['answered_count'];
            $correctCount = (int) $itemStats[$itemNumber]['correct_count'];

            $correctPercent = $answeredCount > 0
                ? round(($correctCount / $answeredCount) * 100, 2)
                : null;

            $itemSummary[] = [
                'item_number' => $itemNumber,
                'question_text' => $itemQuestionTexts[$itemNumber] ?? null,
                'started_count' => $startedCount,
                'answered_count' => $answeredCount,
                'correct_count' => $correctCount,
                'answered_percent' => $startedCount > 0
                    ? round(($answeredCount / $startedCount) * 100, 2)
                    : 0.0,
                'correct_percent' => $correctPercent,
            ];
        }

        $scoreValues = collect($rows)
            ->map(fn (array $row) => $row['attempt']['score_percent'] ?? null)
            ->filter(fn ($value) => !is_null($value))
            ->map(fn ($value) => (float) $value)
            ->values();

        $rankableItems = collect($itemSummary)
            ->filter(fn (array $item) => !is_null($item['correct_percent']))
            ->values();

        $hardestItems = $rankableItems
            ->sortBy(fn (array $item) => (float) $item['correct_percent'])
            ->take(5)
            ->values()
            ->all();

        $easiestItems = $rankableItems
            ->sortByDesc(fn (array $item) => (float) $item['correct_percent'])
            ->take(5)
            ->values()
            ->all();

        ksort($shortAnswerDump);

        return [
            'exam' => [
                'id' => (int) $exam->id,
                'title' => $exam->title,
                'subject' => $exam->subject,
                'total_items' => (int) $exam->total_items,
                'duration_minutes' => (int) $exam->duration_minutes,
                'delivery_mode' => $exam->delivery_mode,
            ],
            'room' => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ],
            'summary' => [
                'students_total' => (int) $students->count(),
                'attempts_started' => (int) collect($rows)->filter(fn (array $row) => !is_null($row['attempt']))->count(),
                'attempts_submitted' => (int) collect($rows)->filter(fn (array $row) => ($row['attempt']['status'] ?? null) === ExamAttempt::STATUS_SUBMITTED)->count(),
                'average_score_percent' => $scoreValues->isNotEmpty() ? round((float) $scoreValues->avg(), 2) : null,
                'highest_score_percent' => $scoreValues->isNotEmpty() ? (float) $scoreValues->max() : null,
                'lowest_score_percent' => $scoreValues->isNotEmpty() ? (float) $scoreValues->min() : null,
            ],
            'rows' => $rows,
            'max_items' => $maxItems,
            'item_summary' => $itemSummary,
            'hardest_items' => $hardestItems,
            'easiest_items' => $easiestItems,
            'short_answer_dump' => array_values($shortAnswerDump),
            'generated_at' => now(),
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function buildCompleteResultsTable(array $session): array
    {
        $maxItems = (int) ($session['max_items'] ?? 1);
        $headers = [
            'Student Name',
            'Student ID',
            'Email',
            'Attempt ID',
            'Attempt Status',
            'Answered Count',
            'Correct Answers',
            'Score %',
            'Started At',
            'Submitted At',
        ];

        for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
            $headers[] = 'Q' . $itemNumber;
        }

        $rows = [];

        foreach (($session['rows'] ?? []) as $row) {
            $attempt = $row['attempt'] ?? null;
            $student = $row['student'] ?? [];
            $line = [
                (string) ($student['name'] ?? ''),
                (string) ($student['student_id'] ?? ''),
                (string) ($student['email'] ?? ''),
                $attempt['id'] ?? '',
                (string) ($attempt['status'] ?? 'not_started'),
                $attempt['answered_count'] ?? 0,
                $attempt['correct_answers'] ?? 0,
                is_null($attempt['score_percent'] ?? null) ? '' : (float) $attempt['score_percent'],
                isset($attempt['started_at']) && $attempt['started_at']
                    ? optional($attempt['started_at'])->format('Y-m-d H:i:s')
                    : '',
                isset($attempt['submitted_at']) && $attempt['submitted_at']
                    ? optional($attempt['submitted_at'])->format('Y-m-d H:i:s')
                    : '',
            ];

            for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
                $line[] = (string) (($row['item_status_labels'][$itemNumber] ?? 'No Answer'));
            }

            $rows[] = $line;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildAnswerKey(Exam $exam): array
    {
        if (!$exam->question_bank_id) {
            return [];
        }

        $query = QuestionBankQuestion::query()
            ->where('question_bank_id', (int) $exam->question_bank_id)
            ->with('options:id,question_bank_question_id,option_label,option_text,is_correct')
            ->orderBy('item_number')
            ->orderBy('id');

        if ((int) $exam->total_items > 0) {
            $query->limit((int) $exam->total_items);
        }

        return $query
            ->get()
            ->values()
            ->map(function (QuestionBankQuestion $question): array {
                $correctAnswer = $this->resolveCorrectAnswer($question);

                return [
                    'item_number' => (int) $question->item_number,
                    'question_text' => (string) $question->question_text,
                    'question_type' => (string) $question->question_type,
                    'correct_answer' => $correctAnswer,
                    'options' => $question->options
                        ->map(fn ($option) => [
                            'label' => $option->option_label,
                            'text' => $option->option_text,
                            'is_correct' => (bool) $option->is_correct,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    public function findLatestAttemptForStudent(Exam $exam, Room $room, int $studentId): ?ExamAttempt
    {
        return ExamAttempt::query()
            ->where('exam_id', (int) $exam->id)
            ->where('room_id', (int) $room->id)
            ->where('user_id', $studentId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, ExamAttempt>
     */
    public function getSessionAttempts(Exam $exam, Room $room): Collection
    {
        $studentIds = $room->members()
            ->where('users.role', User::ROLE_STUDENT)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ExamAttempt::query()
            ->where('exam_id', (int) $exam->id)
            ->where('room_id', (int) $room->id)
            ->when(
                count($studentIds) > 0,
                fn ($query) => $query->whereIn('user_id', $studentIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->with([
                'student:id,name,email,student_id',
                'exam:id,title,subject,total_items,duration_minutes',
                'room:id,name,code',
                'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
                'attemptQuestions.question:id,question_text,question_type,answer_label,answer_text',
                'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
                'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
                'answers.selectedOption:id,option_label,option_text,is_correct',
            ])
            ->orderBy('user_id')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Return one latest attempt per student for an exam session.
     *
     * @return Collection<int, ExamAttempt>
     */
    public function getLatestSessionAttemptsByStudent(Exam $exam, Room $room): Collection
    {
        return $this->getSessionAttempts($exam, $room)
            ->groupBy('user_id')
            ->map(function (Collection $attempts): ?ExamAttempt {
                /** @var ExamAttempt|null $latest */
                $latest = $attempts->reduce(
                    function (?ExamAttempt $carry, ExamAttempt $attempt): ExamAttempt {
                        if (is_null($carry)) {
                            return $attempt;
                        }

                        $attemptTimestamp = optional($attempt->started_at)->timestamp ?? 0;
                        $carryTimestamp = optional($carry->started_at)->timestamp ?? 0;

                        if ($attemptTimestamp > $carryTimestamp) {
                            return $attempt;
                        }

                        if ($attemptTimestamp === $carryTimestamp && (int) $attempt->id > (int) $carry->id) {
                            return $attempt;
                        }

                        return $carry;
                    },
                    null
                );

                return $latest;
            })
            ->filter(fn ($attempt) => $attempt instanceof ExamAttempt)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAttemptReportData(ExamAttempt $attempt): array
    {
        $attempt->loadMissing([
            'student:id,name,email,student_id',
            'exam:id,title,subject,total_items,duration_minutes,delivery_mode,one_take_only,shuffle_questions',
            'room:id,name,code',
            'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
            'attemptQuestions.question:id,question_text,question_type,answer_label,answer_text',
            'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
            'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
            'answers.selectedOption:id,option_label,option_text,is_correct',
        ]);

        $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

        $totalItems = max(
            (int) $attempt->total_items,
            (int) ($attempt->attemptQuestions->max('item_number') ?? 0),
        );

        $answeredCount = (int) $attempt->answers->count();
        $correctAnswers = (int) $attempt->answers->where('is_correct', true)->count();
        $scorePercent = is_null($attempt->score_percent)
            ? ($totalItems > 0 ? round(($correctAnswers / $totalItems) * 100, 2) : 0.0)
            : (float) $attempt->score_percent;

        $items = $attempt->attemptQuestions
            ->sortBy('item_number')
            ->values()
            ->map(function (ExamAttemptQuestion $attemptQuestion) use ($answersByQuestionId): array {
                $question = $attemptQuestion->question;
                $questionId = (int) $attemptQuestion->question_bank_question_id;

                /** @var ExamAttemptAnswer|null $answer */
                $answer = $answersByQuestionId->get($questionId);
                $hasAnswer = !is_null($answer);

                $statusLabel = 'No Answer';
                if ($hasAnswer && $answer?->is_correct === true) {
                    $statusLabel = 'Correct';
                } elseif ($hasAnswer && $answer?->is_correct === false) {
                    $statusLabel = 'Wrong';
                } elseif ($hasAnswer) {
                    $statusLabel = 'Pending Review';
                }

                $selectedAnswerText = $this->formatResponseText($answer);
                $correctAnswer = $question ? $this->resolveCorrectAnswer($question) : [
                    'label' => null,
                    'text' => null,
                    'display' => null,
                ];

                return [
                    'item_number' => (int) $attemptQuestion->item_number,
                    'question_id' => $questionId,
                    'question_text' => $question?->question_text,
                    'question_type' => $question?->question_type,
                    'selected_answer' => $selectedAnswerText,
                    'status_label' => $statusLabel,
                    'is_correct' => $hasAnswer ? $answer?->is_correct : null,
                    'correct_answer' => $correctAnswer,
                    'answered_at' => $answer?->answered_at,
                ];
            })
            ->all();

        return [
            'attempt' => [
                'id' => (int) $attempt->id,
                'status' => $attempt->status,
                'total_items' => $totalItems,
                'answered_count' => $answeredCount,
                'correct_answers' => $correctAnswers,
                'score_percent' => $scorePercent,
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
            ],
            'student' => [
                'id' => (int) ($attempt->student?->id ?? $attempt->user_id),
                'name' => $attempt->student?->name,
                'email' => $attempt->student?->email,
                'student_id' => $attempt->student?->student_id,
            ],
            'exam' => [
                'id' => (int) ($attempt->exam?->id ?? $attempt->exam_id),
                'title' => $attempt->exam?->title,
                'subject' => $attempt->exam?->subject,
                'duration_minutes' => (int) ($attempt->exam?->duration_minutes ?? $attempt->duration_minutes),
                'delivery_mode' => $attempt->exam?->delivery_mode,
            ],
            'room' => [
                'id' => (int) ($attempt->room?->id ?? $attempt->room_id),
                'name' => $attempt->room?->name,
                'code' => $attempt->room?->code,
            ],
            'items' => $items,
        ];
    }

    private function formatResponseText(?ExamAttemptAnswer $answer): ?string
    {
        if (is_null($answer)) {
            return null;
        }

        if ($answer->selectedOption) {
            $optionLabel = trim((string) $answer->selectedOption->option_label);
            $optionText = trim((string) $answer->selectedOption->option_text);
            $display = $optionLabel !== ''
                ? trim($optionLabel . '. ' . $optionText)
                : $optionText;

            return $display !== '' ? $display : null;
        }

        $answerText = trim((string) ($answer->answer_text ?? ''));

        return $answerText !== '' ? $answerText : null;
    }

    /**
     * @return array{label: string|null, text: string|null, display: string|null}
     */
    private function resolveCorrectAnswer(QuestionBankQuestion $question): array
    {
        $correctOption = $question->options->first(fn ($option) => (bool) $option->is_correct);

        $label = trim((string) ($question->answer_label ?? ''));
        $text = trim((string) ($question->answer_text ?? ''));

        if ($label === '' && $correctOption) {
            $label = trim((string) ($correctOption->option_label ?? ''));
        }

        if ($text === '' && $correctOption) {
            $text = trim((string) ($correctOption->option_text ?? ''));
        }

        $display = null;
        if ($label !== '' || $text !== '') {
            $display = $label !== ''
                ? trim($label . '. ' . $text)
                : $text;
        }

        return [
            'label' => $label !== '' ? $label : null,
            'text' => $text !== '' ? $text : null,
            'display' => $display,
        ];
    }
}
