<?php

namespace App\Services;

use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReviewBotService
{
    /**
     * Return available review subjects aggregated from teacher question banks.
     *
     * @return array<int, array{subject: string, question_count: int, bank_count: int}>
     */
    public function listSubjects(): array
    {
        $aggregated = QuestionBank::query()
            ->withCount('questions')
            ->get()
            ->reduce(function (Collection $carry, QuestionBank $bank): Collection {
                $subject = $this->normalizeSubjectLabel($bank->subject);
                $current = $carry->get($subject, [
                    'subject' => $subject,
                    'question_count' => 0,
                    'bank_count' => 0,
                ]);

                $current['question_count'] += (int) ($bank->questions_count ?? 0);
                $current['bank_count'] += 1;

                $carry->put($subject, $current);

                return $carry;
            }, collect());

        return $aggregated
            ->filter(fn (array $subject): bool => (int) ($subject['question_count'] ?? 0) > 0)
            ->sortBy(fn (array $subject): string => Str::lower((string) $subject['subject']))
            ->values()
            ->all();
    }

    /**
     * Generate a review quiz from teacher-authored library content.
     *
     * @return array{
     *   generator: string,
     *   subjects: array<int, string>,
     *   questions: array<int, array<string, mixed>>
     * }
     */
    public function generateQuiz(array $subjects, int $questionCount): array
    {
        $normalizedSubjects = collect($subjects)
            ->map(fn ($subject) => $this->normalizeSubjectLabel($subject))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedSubjects->isEmpty()) {
            throw new RuntimeException('Pick at least one subject to review.');
        }

        $sourceQuestions = $this->resolveSourceQuestions($normalizedSubjects->all(), $questionCount);

        if ($sourceQuestions->isEmpty()) {
            throw new RuntimeException('No teacher review material is available for the selected subjects yet.');
        }

        $generatedQuestions = $this->generateWithAi($sourceQuestions, $normalizedSubjects->all(), $questionCount);
        $generator = 'ai';

        if ($generatedQuestions === null || $generatedQuestions === []) {
            $generatedQuestions = $this->generateFallback($sourceQuestions, $questionCount);
            $generator = 'library_remix';
        }

        if ($generatedQuestions === []) {
            throw new RuntimeException('Unable to build a review set right now. Please try another subject mix.');
        }

        return [
            'generator' => $generator,
            'subjects' => $normalizedSubjects->all(),
            'questions' => array_slice($generatedQuestions, 0, $questionCount),
        ];
    }

    /**
     * @param  array<int, string>  $subjects
     * @return Collection<int, QuestionBankQuestion>
     */
    protected function resolveSourceQuestions(array $subjects, int $questionCount): Collection
    {
        $matchingBankIds = QuestionBank::query()
            ->select(['id', 'subject'])
            ->get()
            ->filter(fn (QuestionBank $bank): bool => in_array($this->normalizeSubjectLabel($bank->subject), $subjects, true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($matchingBankIds === []) {
            return collect();
        }

        $poolLimit = min(max($questionCount * 2, $questionCount + 4), 24);

        return QuestionBankQuestion::query()
            ->with([
                'questionBank:id,title,subject',
                'options:id,question_bank_question_id,option_label,option_text,is_correct',
            ])
            ->whereIn('question_bank_id', $matchingBankIds)
            ->inRandomOrder()
            ->limit($poolLimit)
            ->get()
            ->values();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @param  array<int, string>  $subjects
     * @return array<int, array<string, mixed>>|null
     */
    protected function generateWithAi(Collection $sourceQuestions, array $subjects, int $questionCount): ?array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        if ($apiKey === '') {
            return null;
        }

        $systemPrompt = <<<'PROMPT'
You create student practice quizzes from teacher-authored source material.

Generate fresh review questions that test the same concept but DO NOT copy the original wording, sentence structure, or answer choices verbatim.

Rules:
- Return valid JSON only.
- Return exactly the requested number of questions.
- Keep all output classroom-safe and factually consistent with the source concepts.
- Each question must be either "multiple_choice" or "true_false".
- For "multiple_choice", provide exactly 4 options and exactly one correct option id.
- For "true_false", provide exactly 2 options: True and False, and identify the correct option id.
- Include a short explanation for the correct answer.
- Never mention source question ids, item numbers, or teacher bank titles.
PROMPT;

        $userPrompt = [
            'question_count' => $questionCount,
            'subjects' => $subjects,
            'sources' => $sourceQuestions->map(function (QuestionBankQuestion $question): array {
                $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);

                return [
                    'source_id' => (int) $question->id,
                    'subject' => $this->normalizeSubjectLabel($question->questionBank?->subject),
                    'question_type' => (string) ($question->question_type ?? QuestionBankQuestion::TYPE_MULTIPLE_CHOICE),
                    'question_text' => trim((string) ($question->question_text ?? '')),
                    'answer_text' => trim((string) ($question->answer_text ?? '')),
                    'correct_option_label' => $correctOption?->option_label,
                    'options' => $question->options
                        ->map(fn (QuestionBankOption $option): array => [
                            'label' => $option->option_label,
                            'text' => trim((string) $option->option_text),
                            'is_correct' => (bool) $option->is_correct,
                        ])
                        ->values()
                        ->all(),
                ];
            })->all(),
            'response_schema' => [
                'questions' => [
                    [
                        'prompt' => 'string',
                        'subject' => 'string',
                        'type' => 'multiple_choice | true_false',
                        'options' => [
                            ['id' => 'a', 'text' => 'option text'],
                        ],
                        'correct_option_id' => 'a',
                        'explanation' => 'string',
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(75)
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.8,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => json_encode($userPrompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
                    ],
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        $content = data_get($response, 'choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $questions = $this->normalizeGeneratedQuestions($decoded['questions'] ?? [], $subjects);

        return $questions === [] ? null : $questions;
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array<int, array<string, mixed>>
     */
    protected function generateFallback(Collection $sourceQuestions, int $questionCount): array
    {
        $answerPool = $sourceQuestions
            ->flatMap(function (QuestionBankQuestion $question): array {
                $values = [];

                foreach ($question->options as $option) {
                    $text = trim((string) $option->option_text);
                    if ($text !== '') {
                        $values[] = $text;
                    }
                }

                $answerText = trim((string) ($question->answer_text ?? ''));
                if ($answerText !== '') {
                    $values[] = $answerText;
                }

                return $values;
            })
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values();

        return $sourceQuestions
            ->take($questionCount)
            ->values()
            ->map(function (QuestionBankQuestion $question, int $index) use ($answerPool): array {
                $questionType = (string) ($question->question_type ?? QuestionBankQuestion::TYPE_MULTIPLE_CHOICE);
                $subject = $this->normalizeSubjectLabel($question->questionBank?->subject);

                if ($questionType === QuestionBankQuestion::TYPE_TRUE_FALSE) {
                    [$correctId, $options] = $this->buildTrueFalseOptions($question);

                    return [
                        'id' => 'review-' . ($index + 1),
                        'subject' => $subject,
                        'type' => QuestionBankQuestion::TYPE_TRUE_FALSE,
                        'prompt' => $this->fallbackTrueFalsePrompt($question->question_text, $subject),
                        'options' => $options,
                        'correct_option_id' => $correctId,
                        'explanation' => $this->fallbackExplanation($question),
                    ];
                }

                [$correctId, $options] = $this->buildMultipleChoiceOptions($question, $answerPool);

                return [
                    'id' => 'review-' . ($index + 1),
                    'subject' => $subject,
                    'type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                    'prompt' => $this->fallbackMultipleChoicePrompt($question->question_text, $subject),
                    'options' => $options,
                    'correct_option_id' => $correctId,
                    'explanation' => $this->fallbackExplanation($question),
                ];
            })
            ->all();
    }

    /**
     * @param  mixed  $rawQuestions
     * @param  array<int, string>  $allowedSubjects
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeGeneratedQuestions(mixed $rawQuestions, array $allowedSubjects): array
    {
        if (!is_array($rawQuestions)) {
            return [];
        }

        $allowedSubjectLookup = collect($allowedSubjects)
            ->mapWithKeys(fn (string $subject): array => [Str::lower($subject) => $subject])
            ->all();

        $normalized = [];

        foreach ($rawQuestions as $index => $question) {
            if (!is_array($question)) {
                continue;
            }

            $prompt = trim((string) ($question['prompt'] ?? ''));
            $type = trim((string) ($question['type'] ?? QuestionBankQuestion::TYPE_MULTIPLE_CHOICE));
            $subjectKey = Str::lower($this->normalizeSubjectLabel($question['subject'] ?? 'General'));
            $subject = $allowedSubjectLookup[$subjectKey] ?? $this->normalizeSubjectLabel($question['subject'] ?? 'General');
            $correctOptionId = trim((string) ($question['correct_option_id'] ?? ''));
            $explanation = trim((string) ($question['explanation'] ?? ''));
            $rawOptions = is_array($question['options'] ?? null) ? $question['options'] : [];

            if ($prompt === '' || !in_array($type, [QuestionBankQuestion::TYPE_MULTIPLE_CHOICE, QuestionBankQuestion::TYPE_TRUE_FALSE], true)) {
                continue;
            }

            $options = collect($rawOptions)
                ->map(function ($option, int $optionIndex): ?array {
                    if (!is_array($option)) {
                        return null;
                    }

                    $id = trim((string) ($option['id'] ?? ''));
                    $text = trim((string) ($option['text'] ?? ''));

                    if ($text === '') {
                        return null;
                    }

                    return [
                        'id' => $id !== '' ? $id : 'opt-' . ($optionIndex + 1),
                        'text' => $text,
                    ];
                })
                ->filter()
                ->values();

            if ($options->count() < 2) {
                continue;
            }

            if ($correctOptionId === '' || !$options->contains(fn (array $option): bool => $option['id'] === $correctOptionId)) {
                continue;
            }

            $normalized[] = [
                'id' => 'review-' . ((int) $index + 1),
                'subject' => $subject,
                'type' => $type,
                'prompt' => $prompt,
                'options' => $options->all(),
                'correct_option_id' => $correctOptionId,
                'explanation' => $explanation !== '' ? $explanation : 'Review the correct concept and try to explain why it fits best.',
            ];
        }

        return $normalized;
    }

    /**
     * @return array{0: string, 1: array<int, array{id: string, text: string}>}
     */
    protected function buildTrueFalseOptions(QuestionBankQuestion $question): array
    {
        $answerText = trim((string) ($question->answer_text ?? ''));
        $answerLabel = Str::upper(trim((string) ($question->answer_label ?? '')));

        $isTrue = in_array(Str::lower($answerText), ['true', 't'], true) || $answerLabel === 'T';
        $correctId = $isTrue ? 'true' : 'false';

        return [
            $correctId,
            [
                ['id' => 'true', 'text' => 'True'],
                ['id' => 'false', 'text' => 'False'],
            ],
        ];
    }

    /**
     * @param  Collection<int, string>  $answerPool
     * @return array{0: string, 1: array<int, array{id: string, text: string}>}
     */
    protected function buildMultipleChoiceOptions(QuestionBankQuestion $question, Collection $answerPool): array
    {
        $options = $question->options
            ->map(fn (QuestionBankOption $option): array => [
                'text' => trim((string) $option->option_text),
                'is_correct' => (bool) $option->is_correct,
            ])
            ->filter(fn (array $option): bool => $option['text'] !== '')
            ->values();

        $questionCorrectAnswer = trim((string) ($question->answer_text ?? ''));
        if ($questionCorrectAnswer === '') {
            $questionCorrectAnswer = (string) ($options->first(fn (array $option): bool => $option['is_correct'])['text'] ?? '');
        }

        $resolvedOptions = $options
            ->map(fn (array $option): string => $option['text'])
            ->filter()
            ->values();

        if ($resolvedOptions->count() < 4) {
            $distractors = $answerPool
                ->reject(fn (string $value): bool => Str::lower($value) === Str::lower($questionCorrectAnswer))
                ->reject(fn (string $value): bool => $resolvedOptions->contains(fn (string $existing): bool => Str::lower($existing) === Str::lower($value)))
                ->take(4 - $resolvedOptions->count());

            $resolvedOptions = $resolvedOptions
                ->concat($distractors)
                ->filter()
                ->values();
        }

        if ($resolvedOptions->isEmpty()) {
            $resolvedOptions = collect([$questionCorrectAnswer])->filter()->values();
        }

        if ($resolvedOptions->count() < 2) {
            $resolvedOptions = $resolvedOptions
                ->push('Review the lesson again')
                ->unique()
                ->values();
        }

        if ($resolvedOptions->count() < 4) {
            $resolvedOptions = $resolvedOptions
                ->concat(collect([
                    'A closely related idea',
                    'A common misconception',
                    'An unrelated detail',
                ]))
                ->unique()
                ->take(4)
                ->values();
        }

        $shuffledOptions = $resolvedOptions->shuffle()->take(4)->values();

        if (!$shuffledOptions->contains(fn (string $value): bool => Str::lower($value) === Str::lower($questionCorrectAnswer))) {
            $shuffledOptions = $shuffledOptions
                ->slice(0, max($shuffledOptions->count() - 1, 0))
                ->push($questionCorrectAnswer)
                ->shuffle()
                ->values();
        }

        $letterIds = ['a', 'b', 'c', 'd'];
        $correctId = 'a';
        $formattedOptions = $shuffledOptions
            ->map(function (string $text, int $optionIndex) use ($letterIds, $questionCorrectAnswer, &$correctId): array {
                $optionId = $letterIds[$optionIndex] ?? 'opt-' . ($optionIndex + 1);

                if (Str::lower($text) === Str::lower($questionCorrectAnswer)) {
                    $correctId = $optionId;
                }

                return [
                    'id' => $optionId,
                    'text' => $text,
                ];
            })
            ->all();

        return [$correctId, $formattedOptions];
    }

    protected function fallbackMultipleChoicePrompt(?string $questionText, string $subject): string
    {
        $cleaned = $this->cleanQuestionText($questionText);

        return sprintf(
            'Review focus for %s: choose the best answer for this practice item. %s',
            $subject,
            $cleaned
        );
    }

    protected function fallbackTrueFalsePrompt(?string $questionText, string $subject): string
    {
        $cleaned = $this->cleanQuestionText($questionText);

        return sprintf(
            'Review focus for %s: decide whether this statement is true or false. %s',
            $subject,
            $cleaned
        );
    }

    protected function fallbackExplanation(QuestionBankQuestion $question): string
    {
        $answerText = trim((string) ($question->answer_text ?? ''));

        if ($answerText !== '') {
            return sprintf('The correct idea from the teacher library points to: %s', $answerText);
        }

        $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);

        if ($correctOption && trim((string) $correctOption->option_text) !== '') {
            return sprintf('The best answer matches the teacher library concept: %s', trim((string) $correctOption->option_text));
        }

        return 'The correct option matches the intended concept from the teacher library.';
    }

    protected function cleanQuestionText(?string $questionText): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) $questionText));
        $normalized = (string) $normalized;

        if ($normalized === '') {
            return 'Use the concept from your selected subject to answer this item.';
        }

        $replacements = [
            '/^which of the following/i' => 'Select the option that best fits this review prompt',
            '/^what is/i' => 'Identify',
            '/^who is/i' => 'Determine who',
            '/^when is/i' => 'Determine when',
            '/^where is/i' => 'Determine where',
            '/^why is/i' => 'Choose the best explanation for why',
            '/^how does/i' => 'Choose the best explanation for how',
        ];

        foreach ($replacements as $pattern => $replacement) {
            if (preg_match($pattern, $normalized) === 1) {
                $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
                break;
            }
        }

        $normalized = rtrim($normalized, " \t\n\r\0\x0B.?!");

        return $normalized . '?';
    }

    protected function normalizeSubjectLabel(mixed $subject): string
    {
        $normalized = trim((string) $subject);

        return $normalized !== '' ? $normalized : 'General';
    }
}
