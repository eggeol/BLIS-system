<?php

namespace App\Services;

use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ReviewBotService
{
    /**
     * Return available review subjects from the built-in BLIS core reviewer.
     *
     * @return array<int, array{subject: string, description: string, focus_areas: array<int, string>, topic_count: int, bot_ready: bool}>
     */
    public function listSubjects(): array
    {
        return collect($this->coreSubjectProfiles())
            ->map(function (array $profile, string $subject): array {
                return [
                    'subject' => $subject,
                    'description' => $profile['description'],
                    'focus_areas' => $profile['focus_areas'],
                    'topic_count' => count($profile['topic_blueprints']),
                    'bot_ready' => true,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Generate a review quiz from the built-in BLIS core reviewer.
     *
     * @return array{
     *   generator: string,
     *   subjects: array<int, string>,
     *   questions: array<int, array<string, mixed>>
     * }
     */
    public function generateQuiz(array $subjects, int $questionCount): array
    {
        $profiles = $this->coreSubjectProfiles();

        $normalizedSubjects = collect($subjects)
            ->map(fn ($subject) => $this->normalizeSubjectLabel($subject))
            ->filter(fn (string $subject): bool => array_key_exists($subject, $profiles))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedSubjects->isEmpty()) {
            throw new RuntimeException('Pick at least one BLIS core subject to review.');
        }

        $generatedQuestions = $this->generateFromCoreBlueprints(
            $normalizedSubjects->all(),
            $questionCount
        );
        $generator = 'core_blueprint';

        if ($generatedQuestions === []) {
            throw new RuntimeException('Unable to build a BLIS review set right now. Please try another subject mix.');
        }

        return [
            'generator' => $generator,
            'subjects' => $normalizedSubjects->all(),
            'questions' => array_slice($generatedQuestions, 0, $questionCount),
        ];
    }

    /**
     * @param  array<int, string>  $subjects
     * @return array<int, array<string, mixed>>
     */
    protected function generateFromCoreBlueprints(array $subjects, int $questionCount): array
    {
        $profiles = $this->coreSubjectProfiles();
        $subjectRotation = collect($subjects)->shuffle()->values()->all();

        if ($subjectRotation === []) {
            return [];
        }

        $blueprintsBySubject = [];
        $subjectCounters = [];

        foreach ($subjectRotation as $subject) {
            $blueprintsBySubject[$subject] = collect($profiles[$subject]['topic_blueprints'] ?? [])
                ->shuffle()
                ->values()
                ->all();
            $subjectCounters[$subject] = 0;
        }

        $questions = [];

        for ($index = 0; $index < $questionCount; $index++) {
            $subject = $subjectRotation[$index % count($subjectRotation)];
            $subjectBlueprints = $blueprintsBySubject[$subject] ?? [];

            if ($subjectBlueprints === []) {
                continue;
            }

            $subjectPointer = (int) ($subjectCounters[$subject] ?? 0);
            $blueprint = $subjectBlueprints[$subjectPointer % count($subjectBlueprints)];

            $questions[] = $this->buildBlueprintQuestion(
                $blueprint,
                $subject,
                $subjectPointer,
                $index
            );

            $subjectCounters[$subject] = $subjectPointer + 1;
        }

        return $questions;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    protected function buildBlueprintQuestion(array $blueprint, string $subject, int $variantIndex, int $questionIndex): array
    {
        [$correctOptionId, $options] = $this->buildBlueprintOptions($blueprint);

        return [
            'id' => 'review-' . ($questionIndex + 1),
            'subject' => $subject,
            'type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'prompt' => $this->buildBlueprintPrompt((string) ($blueprint['prompt'] ?? ''), $subject, $variantIndex),
            'options' => $options,
            'correct_option_id' => $correctOptionId,
            'explanation' => trim((string) ($blueprint['explanation'] ?? '')) !== ''
                ? trim((string) $blueprint['explanation'])
                : 'Review the key concept behind the correct answer before moving to the next item.',
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{0: string, 1: array<int, array{id: string, text: string}>}
     */
    protected function buildBlueprintOptions(array $blueprint): array
    {
        $correctAnswer = trim((string) ($blueprint['answer'] ?? ''));
        $rawDistractors = is_array($blueprint['distractors'] ?? null) ? $blueprint['distractors'] : [];

        $options = collect(array_merge([$correctAnswer], $rawDistractors))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values();

        if ($options->count() < 4) {
            $options = $options
                ->concat(collect([
                    'A different library process',
                    'A related but incorrect practice',
                    'A term from another subject area',
                ]))
                ->unique(fn (string $value): string => Str::lower($value))
                ->take(4)
                ->values();
        }

        $shuffled = $options->shuffle()->take(4)->values();

        if (!$shuffled->contains(fn (string $value): bool => Str::lower($value) === Str::lower($correctAnswer))) {
            $shuffled = $shuffled
                ->slice(0, max($shuffled->count() - 1, 0))
                ->push($correctAnswer)
                ->shuffle()
                ->values();
        }

        $letterIds = ['a', 'b', 'c', 'd'];
        $correctId = 'a';

        $formattedOptions = $shuffled
            ->map(function (string $text, int $optionIndex) use ($letterIds, $correctAnswer, &$correctId): array {
                $id = $letterIds[$optionIndex] ?? 'opt-' . ($optionIndex + 1);

                if (Str::lower($text) === Str::lower($correctAnswer)) {
                    $correctId = $id;
                }

                return [
                    'id' => $id,
                    'text' => $text,
                ];
            })
            ->all();

        return [$correctId, $formattedOptions];
    }

    protected function buildBlueprintPrompt(string $prompt, string $subject, int $variantIndex): string
    {
        return $this->ensureQuestionPrompt($prompt);
    }

    protected function ensureQuestionPrompt(string $prompt): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($prompt));
        $normalized = (string) $normalized;

        if ($normalized === '') {
            return 'Which answer best matches the BLIS concept being reviewed?';
        }

        return rtrim($normalized, " \t\n\r\0\x0B.?!") . '?';
    }

    /**
     * @param  array<int, string>  $subjects
     * @return array<int, array<string, mixed>>
     */
    protected function subjectProfilePayload(array $subjects): array
    {
        $profiles = $this->coreSubjectProfiles();

        return collect($subjects)
            ->map(function (string $subject) use ($profiles): array {
                $profile = $profiles[$subject] ?? [
                    'description' => '',
                    'focus_areas' => [],
                    'topic_blueprints' => [],
                ];

                return [
                    'subject' => $subject,
                    'description' => $profile['description'],
                    'focus_areas' => $profile['focus_areas'],
                    'bot_topics' => collect($profile['topic_blueprints'] ?? [])
                        ->take(6)
                        ->map(fn (array $topic): array => [
                            'prompt' => $topic['prompt'] ?? '',
                            'answer' => $topic['answer'] ?? '',
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array<int, array<string, mixed>>
     */
    protected function sourceQuestionPayload(Collection $sourceQuestions): array
    {
        return $sourceQuestions
            ->take(12)
            ->map(function (QuestionBankQuestion $question): array {
                $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);

                return [
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
            })
            ->values()
            ->all();
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

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function coreSubjectProfiles(): array
    {
        return [
            'Cataloging and Classification' => [
                'description' => 'Practice bibliographic description, call numbers, and subject access tools used to organize collections.',
                'focus_areas' => [
                    'RDA and descriptive cataloging',
                    'Classification systems and call numbers',
                    'Subject headings and access points',
                ],
                'topic_blueprints' => [
                    [
                        'prompt' => 'Which standard is commonly used for modern bibliographic description in libraries',
                        'answer' => 'Resource Description and Access (RDA)',
                        'distractors' => ['Selective Dissemination of Information', 'Boolean retrieval only', 'Current Awareness Service'],
                        'explanation' => 'RDA is the modern descriptive cataloging standard used to record bibliographic details and relationships.',
                    ],
                    [
                        'prompt' => 'What is the main purpose of a classification number in a library collection',
                        'answer' => 'To place materials on similar subjects together',
                        'distractors' => ['To record the borrower history of an item', 'To replace the title of the material', 'To show the date the book was donated'],
                        'explanation' => 'Classification numbers group materials by subject so users can find related resources together.',
                    ],
                    [
                        'prompt' => 'Why do libraries use controlled subject headings in catalogs',
                        'answer' => 'To make subject searching more consistent',
                        'distractors' => ['To shorten every book title', 'To remove all author entries', 'To convert print books into e-books'],
                        'explanation' => 'Controlled subject headings improve consistency by using standard terms for similar topics.',
                    ],
                    [
                        'prompt' => 'What does a Cutter number usually help distinguish in a call number',
                        'answer' => 'Items with the same subject but different authors or titles',
                        'distractors' => ['The total number of pages in the book', 'Whether the material is overdue', 'The purchase price of the item'],
                        'explanation' => 'The Cutter number further arranges materials within the same classification by author or title.',
                    ],
                    [
                        'prompt' => 'Which catalog entry element most directly helps a user search for materials by topic',
                        'answer' => 'Subject access point',
                        'distractors' => ['Barcode number only', 'Circulation transaction log', 'Shelf dust jacket'],
                        'explanation' => 'Subject access points connect users to resources through topic-based searching.',
                    ],
                    [
                        'prompt' => 'What is the best description of descriptive cataloging',
                        'answer' => 'Recording the physical and bibliographic details of an item',
                        'distractors' => ['Removing duplicates from the shelves', 'Training users in database searching', 'Preparing annual budget requests'],
                        'explanation' => 'Descriptive cataloging focuses on the identifying details of the resource itself.',
                    ],
                    [
                        'prompt' => 'Why is authority control important in a library catalog',
                        'answer' => 'It keeps names and subjects consistent across records',
                        'distractors' => ['It replaces shelving labels', 'It removes the need for call numbers', 'It is used only for circulation fines'],
                        'explanation' => 'Authority control standardizes headings so users can retrieve related records more accurately.',
                    ],
                    [
                        'prompt' => 'What is the main purpose of the Dewey Decimal Classification system',
                        'answer' => 'To organize materials by subject into a numerical scheme',
                        'distractors' => ['To record borrower transactions', 'To create abstracts for journals', 'To schedule library events'],
                        'explanation' => 'DDC arranges materials by subject using numbers that help place related resources together.',
                    ],
                    [
                        'prompt' => 'Which bibliographic element helps users tell one version of a work from another',
                        'answer' => 'Edition statement',
                        'distractors' => ['Issue desk stamp', 'Shelf marker only', 'Due date slip'],
                        'explanation' => 'The edition statement identifies which version or revision of a resource is being described.',
                    ],
                    [
                        'prompt' => 'What is the role of a call number on a library item',
                        'answer' => 'To show the item’s subject location and arrangement on the shelf',
                        'distractors' => ['To summarize the entire book', 'To list the previous borrowers', 'To replace the accession number'],
                        'explanation' => 'A call number helps both staff and users locate the item and see its position among related materials.',
                    ],
                    [
                        'prompt' => 'Which access point is most useful when a user searches for a known author',
                        'answer' => 'Author heading',
                        'distractors' => ['Barcode sequence', 'Issue slip record', 'Binding note only'],
                        'explanation' => 'The author heading provides a direct way to retrieve works associated with a specific creator.',
                    ],
                    [
                        'prompt' => 'Why do libraries assign subject headings separately from titles',
                        'answer' => 'Because titles alone may not clearly describe the actual topic',
                        'distractors' => ['Because subject headings replace classification numbers', 'Because titles are not part of catalog records', 'Because subject headings are used only for journals'],
                        'explanation' => 'Subject headings improve topic access when titles are vague, brief, or creative rather than descriptive.',
                    ],
                ],
            ],
            'Indexing and Abstracting' => [
                'description' => 'Review concepts about indexing terms, abstracts, retrieval quality, and subject analysis.',
                'focus_areas' => [
                    'Descriptors and controlled vocabulary',
                    'Abstract types and functions',
                    'Precision, recall, and retrieval quality',
                ],
                'topic_blueprints' => [
                    [
                        'prompt' => 'What is the main purpose of an abstract in an information source',
                        'answer' => 'To summarize the content so users can judge relevance quickly',
                        'distractors' => ['To replace the full text permanently', 'To list circulation fines', 'To show the shelf location of the source'],
                        'explanation' => 'An abstract gives a concise overview so users can decide whether a source is relevant.',
                    ],
                    [
                        'prompt' => 'Which measure improves when a search retrieves mostly relevant records and very few irrelevant ones',
                        'answer' => 'Precision',
                        'distractors' => ['Recall only', 'Accessioning', 'Shelf rectification'],
                        'explanation' => 'Precision measures how many retrieved records are actually relevant.',
                    ],
                    [
                        'prompt' => 'Why do indexing systems use controlled vocabulary',
                        'answer' => 'To reduce variation in terms for the same concept',
                        'distractors' => ['To hide records from beginners', 'To eliminate abstracts from articles', 'To convert indexes into classification schedules'],
                        'explanation' => 'Controlled vocabulary standardizes terms and improves retrieval consistency.',
                    ],
                    [
                        'prompt' => 'What is a citation index mainly used for',
                        'answer' => 'Tracing how documents are linked through references',
                        'distractors' => ['Replacing book classification schemes', 'Recording library attendance', 'Scheduling reference desk shifts'],
                        'explanation' => 'Citation indexes help track the relationship between works through references and citations.',
                    ],
                    [
                        'prompt' => 'What does exhaustive indexing mean',
                        'answer' => 'Assigning many relevant terms to cover multiple aspects of a document',
                        'distractors' => ['Using only one keyword for every record', 'Indexing only the document title', 'Removing broad concepts from the record'],
                        'explanation' => 'Exhaustive indexing covers more aspects of the content through several relevant terms.',
                    ],
                    [
                        'prompt' => 'Which abstract type usually presents the main findings or conclusions of a document',
                        'answer' => 'Informative abstract',
                        'distractors' => ['Call number abstract', 'Shelf list abstract', 'Authority abstract'],
                        'explanation' => 'An informative abstract provides more complete details, including key results or conclusions.',
                    ],
                    [
                        'prompt' => 'What does recall measure in information retrieval',
                        'answer' => 'How many of the relevant records were actually retrieved',
                        'distractors' => ['How many shelves are occupied', 'How many users visited the library', 'How many copies were accessioned'],
                        'explanation' => 'Recall focuses on whether the search was able to recover the relevant records that exist in the system.',
                    ],
                    [
                        'prompt' => 'What is the main advantage of using a thesaurus in indexing',
                        'answer' => 'It shows preferred terms and related terms for consistent subject access',
                        'distractors' => ['It replaces classification schedules', 'It records circulation statistics', 'It assigns barcode numbers automatically'],
                        'explanation' => 'A thesaurus helps indexers choose standardized terms and understand related concepts.',
                    ],
                    [
                        'prompt' => 'Which type of abstract mainly describes the topic without giving detailed findings',
                        'answer' => 'Indicative abstract',
                        'distractors' => ['Shelf abstract', 'Classified abstract', 'Control abstract'],
                        'explanation' => 'An indicative abstract tells the user what the document is about without fully presenting results or conclusions.',
                    ],
                    [
                        'prompt' => 'Why is specificity important in indexing',
                        'answer' => 'It matches the indexing term closely to the actual topic of the document',
                        'distractors' => ['It removes every broad term from the system', 'It guarantees 100 percent recall', 'It prevents the use of abstracts'],
                        'explanation' => 'Specific terms improve retrieval by representing the content more accurately.',
                    ],
                    [
                        'prompt' => 'What is keyword indexing mainly based on',
                        'answer' => 'Words taken directly from the document text or title',
                        'distractors' => ['Shelf order numbers only', 'Library opening hours', 'Budget approval codes'],
                        'explanation' => 'Keyword indexing often uses words that appear naturally in the text, especially in titles and abstracts.',
                    ],
                    [
                        'prompt' => 'Why do indexers sometimes assign broader and narrower terms together',
                        'answer' => 'To improve access from general topics to more specific ones',
                        'distractors' => ['To avoid all subject analysis', 'To make abstracts longer than the full text', 'To replace citation indexes'],
                        'explanation' => 'Using related broader and narrower terms can support better retrieval across different search approaches.',
                    ],
                ],
            ],
            'Information Technology' => [
                'description' => 'Generate practice questions about search systems, metadata, digital tools, and technology used in libraries.',
                'focus_areas' => [
                    'OPAC, ILS, and discovery tools',
                    'Search strategy and Boolean logic',
                    'Digital systems, security, and interoperability',
                ],
                'topic_blueprints' => [
                    [
                        'prompt' => 'What is the main role of an OPAC in a library',
                        'answer' => 'To let users search the library collection electronically',
                        'distractors' => ['To classify books without metadata', 'To replace staff scheduling tools', 'To generate annual audit reports'],
                        'explanation' => 'An OPAC allows users to search the library collection through an online catalog interface.',
                    ],
                    [
                        'prompt' => 'Which Boolean operator is best for narrowing a search by requiring all terms to appear',
                        'answer' => 'AND',
                        'distractors' => ['OR', 'NOT ONLY', 'PLUS'],
                        'explanation' => 'AND narrows a search because all connected terms must appear in the retrieved records.',
                    ],
                    [
                        'prompt' => 'Why is metadata important in digital library systems',
                        'answer' => 'It helps describe, organize, and retrieve digital resources',
                        'distractors' => ['It removes the need for cataloging rules', 'It replaces every full-text file', 'It is used only for overdue notices'],
                        'explanation' => 'Metadata supports organization, access, management, and retrieval of digital resources.',
                    ],
                    [
                        'prompt' => 'What is a key benefit of an integrated library system',
                        'answer' => 'It connects major library functions in one platform',
                        'distractors' => ['It prevents all catalog updates', 'It works only without internet access', 'It stores books without accession records'],
                        'explanation' => 'An integrated library system links cataloging, circulation, acquisitions, and related modules together.',
                    ],
                    [
                        'prompt' => 'Which security practice best helps protect librarian and student accounts',
                        'answer' => 'Using strong passwords and multi-factor authentication',
                        'distractors' => ['Sharing one login for all staff', 'Posting passwords near the workstation', 'Disabling all system backups'],
                        'explanation' => 'Strong passwords and multi-factor authentication reduce the risk of unauthorized access.',
                    ],
                    [
                        'prompt' => 'Why do libraries care about interoperability standards such as MARC or Dublin Core mappings',
                        'answer' => 'They make it easier to exchange data between systems',
                        'distractors' => ['They eliminate the need for subject analysis', 'They replace collection development policies', 'They are used only in manual shelving'],
                        'explanation' => 'Interoperability standards support data sharing across catalogs, repositories, and other information systems.',
                    ],
                    [
                        'prompt' => 'What is the main benefit of using RFID or barcodes in circulation work',
                        'answer' => 'They make item identification and transaction processing faster',
                        'distractors' => ['They replace cataloging rules completely', 'They remove the need for user accounts', 'They create subject headings automatically'],
                        'explanation' => 'RFID and barcodes speed up check-in, check-out, and inventory tasks by identifying items efficiently.',
                    ],
                    [
                        'prompt' => 'Why are regular backups important in library information systems',
                        'answer' => 'They help recover data after system failure or accidental loss',
                        'distractors' => ['They reduce the number of catalog records', 'They replace metadata entry', 'They prevent users from searching the OPAC'],
                        'explanation' => 'Backups protect important data and support system recovery when problems occur.',
                    ],
                    [
                        'prompt' => 'What is a discovery service designed to do for users',
                        'answer' => 'Search multiple library resources through a single interface',
                        'distractors' => ['Assign classification numbers to books', 'Monitor library building repairs', 'Replace every database subscription'],
                        'explanation' => 'Discovery services allow users to search across catalogs, databases, and other resources from one search point.',
                    ],
                    [
                        'prompt' => 'Which online threat often tricks users into giving passwords through fake messages or websites',
                        'answer' => 'Phishing',
                        'distractors' => ['Metadata mapping', 'Authority control', 'Shelf rectification'],
                        'explanation' => 'Phishing attacks imitate trusted sources to steal account credentials or sensitive information.',
                    ],
                    [
                        'prompt' => 'Why is field searching useful in online databases',
                        'answer' => 'It lets users search specific parts of a record such as author, title, or subject',
                        'distractors' => ['It converts every query into a classification number', 'It removes irrelevant records permanently', 'It works only with printed indexes'],
                        'explanation' => 'Field searching helps narrow results by targeting a specific part of the bibliographic record.',
                    ],
                    [
                        'prompt' => 'What is one advantage of cloud-based library services',
                        'answer' => 'They allow remote access and centralized updates',
                        'distractors' => ['They eliminate all internet dependence', 'They make passwords unnecessary', 'They prevent data sharing between systems'],
                        'explanation' => 'Cloud-based services can support access from different locations and make maintenance easier through centralized management.',
                    ],
                ],
            ],
            'Reference Services' => [
                'description' => 'Focus on user assistance, reference interviews, source evaluation, and information service delivery.',
                'focus_areas' => [
                    'Reference interview and user needs',
                    'Ready reference and referral',
                    'Information literacy and source evaluation',
                ],
                'topic_blueprints' => [
                    [
                        'prompt' => 'What is the main goal of a reference interview',
                        'answer' => 'To clarify the user\'s real information need',
                        'distractors' => ['To assign a classification number immediately', 'To approve the library budget', 'To prepare binding schedules'],
                        'explanation' => 'The reference interview helps the librarian understand exactly what the user needs.',
                    ],
                    [
                        'prompt' => 'Which situation is best handled by ready reference service',
                        'answer' => 'A quick factual question that needs a brief answer',
                        'distractors' => ['A year-long collection development study', 'A full building renovation plan', 'A metadata migration project'],
                        'explanation' => 'Ready reference is designed for quick factual questions that can be answered briefly.',
                    ],
                    [
                        'prompt' => 'When should a librarian make a referral during reference work',
                        'answer' => 'When another source or specialist can better meet the user\'s need',
                        'distractors' => ['When the user asks for the catalog only', 'When the borrower has no library card', 'When the shelves are being read'],
                        'explanation' => 'Referral is appropriate when another resource, institution, or specialist is the better source of help.',
                    ],
                    [
                        'prompt' => 'Which criterion is especially important when evaluating online information for reference use',
                        'answer' => 'Authority of the source',
                        'distractors' => ['Color of the website theme', 'Length of the URL alone', 'Number of advertisements on the page'],
                        'explanation' => 'Authority helps determine whether the source is credible and appropriate for reference work.',
                    ],
                    [
                        'prompt' => 'What is the purpose of a current awareness service in a library',
                        'answer' => 'To alert users to newly available information',
                        'distractors' => ['To replace all reference interviews', 'To classify archival materials', 'To issue overdue fines automatically'],
                        'explanation' => 'Current awareness services keep users informed about new materials or developments in their areas of interest.',
                    ],
                    [
                        'prompt' => 'Which librarian action best supports information literacy during reference service',
                        'answer' => 'Teaching the user how to evaluate and search for information',
                        'distractors' => ['Answering every question without explanation', 'Hiding search terms from the user', 'Limiting access to indexes unnecessarily'],
                        'explanation' => 'Information literacy support helps users become more independent and effective information seekers.',
                    ],
                    [
                        'prompt' => 'Which type of question usually involves helping a user find a place or basic service in the library',
                        'answer' => 'Directional question',
                        'distractors' => ['Subject indexing question', 'Collection development question', 'Authority control question'],
                        'explanation' => 'Directional questions involve locations, facilities, and simple service directions rather than in-depth information searches.',
                    ],
                    [
                        'prompt' => 'Why is active listening important during a reference interview',
                        'answer' => 'It helps the librarian understand the user’s actual need more clearly',
                        'distractors' => ['It replaces the need for reference tools', 'It shortens every answer automatically', 'It avoids all follow-up questions'],
                        'explanation' => 'Active listening helps reveal the real question behind the user’s initial request.',
                    ],
                    [
                        'prompt' => 'Which source is usually best for quick background information on a broad topic',
                        'answer' => 'Encyclopedia',
                        'distractors' => ['Accession register', 'Vendor invoice', 'Circulation shelf list'],
                        'explanation' => 'Encyclopedias are useful starting points when a user needs a general overview of a topic.',
                    ],
                    [
                        'prompt' => 'What does currency mean when evaluating an information source',
                        'answer' => 'How up to date the information is',
                        'distractors' => ['How much the source costs', 'How often the shelves are dusted', 'How many pages the source contains'],
                        'explanation' => 'Currency helps determine whether the information is recent enough for the user’s purpose.',
                    ],
                    [
                        'prompt' => 'What is a pathfinder in library reference work',
                        'answer' => 'A guide that points users to useful sources on a topic',
                        'distractors' => ['A replacement for the OPAC', 'A shelf-reading checklist', 'A fine computation tool'],
                        'explanation' => 'Pathfinders direct users to recommended resources, tools, and search strategies for specific subjects.',
                    ],
                    [
                        'prompt' => 'Why is neutrality important in reference service',
                        'answer' => 'It helps the librarian provide information without unfair bias',
                        'distractors' => ['It prevents users from asking difficult questions', 'It removes the need for evaluation', 'It is used only in acquisitions'],
                        'explanation' => 'Neutral service supports fair, ethical assistance and helps users make their own informed judgments.',
                    ],
                ],
            ],
            'Library Management' => [
                'description' => 'Review management functions, policy work, budgeting, staff support, and service quality decisions.',
                'focus_areas' => [
                    'Planning, organizing, leading, and controlling',
                    'Policy, budgeting, and assessment',
                    'Staff development and risk management',
                ],
                'topic_blueprints' => [
                    [
                        'prompt' => 'Which management function is most closely associated with setting goals and priorities',
                        'answer' => 'Planning',
                        'distractors' => ['Shelving', 'Binding', 'Indexing'],
                        'explanation' => 'Planning focuses on deciding goals, strategies, and priorities before action is taken.',
                    ],
                    [
                        'prompt' => 'What is the main purpose of a library policy',
                        'answer' => 'To guide consistent decisions and actions',
                        'distractors' => ['To replace staff training completely', 'To classify books by call number', 'To remove the need for budgeting'],
                        'explanation' => 'Policies provide direction and consistency for service, operations, and decision-making.',
                    ],
                    [
                        'prompt' => 'Why is budgeting important in library management',
                        'answer' => 'It helps allocate limited funds to priorities and services',
                        'distractors' => ['It assigns subject headings to books', 'It converts abstracts into indexes', 'It replaces collection evaluation'],
                        'explanation' => 'Budgeting helps library managers direct resources toward the most important needs.',
                    ],
                    [
                        'prompt' => 'Which action best supports staff development in a library',
                        'answer' => 'Providing training and professional growth opportunities',
                        'distractors' => ['Avoiding feedback discussions', 'Rotating staff without orientation', 'Removing all written procedures'],
                        'explanation' => 'Staff development improves service quality, confidence, and long-term organizational capacity.',
                    ],
                    [
                        'prompt' => 'What is the value of assessment or performance indicators in library management',
                        'answer' => 'They help measure how well services are meeting goals',
                        'distractors' => ['They replace user feedback entirely', 'They act as classification numbers', 'They are used only for acquisitions'],
                        'explanation' => 'Assessment tools show whether services are effective and where improvement is needed.',
                    ],
                    [
                        'prompt' => 'Why should libraries prepare disaster or continuity plans',
                        'answer' => 'To reduce service disruption and protect collections during emergencies',
                        'distractors' => ['To eliminate catalog records annually', 'To increase overdue penalties automatically', 'To avoid all digital backups'],
                        'explanation' => 'Continuity and disaster plans help libraries respond effectively to emergencies and protect resources.',
                    ],
                    [
                        'prompt' => 'Which management function focuses on assigning tasks and structuring work responsibilities',
                        'answer' => 'Organizing',
                        'distractors' => ['Indexing', 'Weeding', 'Abstracting'],
                        'explanation' => 'Organizing involves arranging people, resources, and tasks so the library can operate effectively.',
                    ],
                    [
                        'prompt' => 'Why are clear job descriptions useful in library management',
                        'answer' => 'They define responsibilities and support accountability',
                        'distractors' => ['They replace staff meetings', 'They serve as call numbers for staff', 'They eliminate the need for training'],
                        'explanation' => 'Job descriptions help staff understand expectations and support consistent supervision.',
                    ],
                    [
                        'prompt' => 'What is the purpose of SWOT analysis in planning',
                        'answer' => 'To examine strengths, weaknesses, opportunities, and threats',
                        'distractors' => ['To assign subject headings', 'To generate abstracts automatically', 'To record borrower fines'],
                        'explanation' => 'SWOT analysis helps managers evaluate the library’s internal and external situation during planning.',
                    ],
                    [
                        'prompt' => 'Why is delegation important for library supervisors',
                        'answer' => 'It allows work to be shared while developing staff capability',
                        'distractors' => ['It removes all responsibility from managers', 'It prevents feedback from being given', 'It is used only in cataloging'],
                        'explanation' => 'Delegation helps manage workload and gives staff opportunities to take responsibility and grow.',
                    ],
                    [
                        'prompt' => 'Which leadership action best supports a positive service culture in a library',
                        'answer' => 'Giving clear direction and constructive feedback',
                        'distractors' => ['Avoiding communication during problems', 'Changing procedures without notice', 'Ignoring user complaints completely'],
                        'explanation' => 'Clear direction and feedback help staff stay aligned and improve service quality.',
                    ],
                    [
                        'prompt' => 'Why are user satisfaction surveys valuable in library management',
                        'answer' => 'They provide evidence about how users view library services',
                        'distractors' => ['They replace budget reports', 'They assign accession numbers', 'They are used only for archives'],
                        'explanation' => 'User surveys provide feedback that can guide service improvements and planning decisions.',
                    ],
                ],
            ],
            'Selection and Acquisition' => [
                'description' => 'Generate questions about choosing materials, verifying records, ordering resources, and managing vendors.',
                'focus_areas' => [
                    'Selection criteria and policy alignment',
                    'Acquisition methods and ordering workflow',
                    'Vendor review and bibliographic verification',
                ],
                'topic_blueprints' => [
                    [
                        'prompt' => 'What should guide the selection of new library materials first',
                        'answer' => 'User needs and the collection development policy',
                        'distractors' => ['Cover design only', 'Random online popularity', 'How heavy the material is'],
                        'explanation' => 'Selection should be guided by the community\'s needs and the library\'s collection policy.',
                    ],
                    [
                        'prompt' => 'Why is bibliographic verification important before ordering a resource',
                        'answer' => 'It helps confirm that the library is ordering the correct item',
                        'distractors' => ['It replaces the need for vendors', 'It determines the shelving sequence only', 'It creates automatic abstracts'],
                        'explanation' => 'Bibliographic verification reduces ordering errors by confirming details such as edition, author, and publisher.',
                    ],
                    [
                        'prompt' => 'Which option is an example of an acquisition method',
                        'answer' => 'Purchase, gift, or exchange',
                        'distractors' => ['Classification, indexing, and abstracting', 'Reference interview, referral, and weeding', 'Budgeting, cataloging, and binding'],
                        'explanation' => 'Libraries can acquire materials through purchase, donation, exchange, and similar methods.',
                    ],
                    [
                        'prompt' => 'Which factor is important when evaluating a vendor for library acquisitions',
                        'answer' => 'Reliability of delivery and service quality',
                        'distractors' => ['Shelf color of the vendor catalog', 'How many posters the vendor prints', 'Whether the vendor creates subject headings'],
                        'explanation' => 'Vendor evaluation often includes reliability, service quality, pricing, and responsiveness.',
                    ],
                    [
                        'prompt' => 'What is the best reason for using a selection tool such as reviews or bibliographies',
                        'answer' => 'To support informed decisions about what to acquire',
                        'distractors' => ['To replace all user requests', 'To postpone cataloging indefinitely', 'To remove circulation records'],
                        'explanation' => 'Selection tools provide evidence and professional guidance for choosing materials.',
                    ],
                    [
                        'prompt' => 'What is a standing order most useful for',
                        'answer' => 'Receiving continuing publications or series automatically',
                        'distractors' => ['Assigning Cutter numbers to fiction', 'Creating catalog authority records', 'Recording reference desk statistics'],
                        'explanation' => 'Standing orders help libraries receive ongoing publications or series without placing each order separately.',
                    ],
                    [
                        'prompt' => 'Why should a library evaluate gifts before adding them to the collection',
                        'answer' => 'Because not all donated materials match the library’s needs or policy',
                        'distractors' => ['Because gifts cannot be cataloged', 'Because donated items never need verification', 'Because gifts automatically replace purchased materials'],
                        'explanation' => 'Gift materials still need to fit the library’s mission, users, and collection standards.',
                    ],
                    [
                        'prompt' => 'What is the main purpose of a desiderata file or purchase request list',
                        'answer' => 'To record titles suggested for possible acquisition',
                        'distractors' => ['To replace bibliographic verification', 'To store overdue borrower names', 'To classify books by subject'],
                        'explanation' => 'A desiderata file keeps track of titles requested or recommended for future selection decisions.',
                    ],
                    [
                        'prompt' => 'Why is price comparison useful during acquisitions',
                        'answer' => 'It helps the library choose cost-effective options from suppliers',
                        'distractors' => ['It replaces collection policy decisions', 'It removes the need for invoices', 'It is used only for journals'],
                        'explanation' => 'Comparing prices supports responsible use of funds while still meeting collection needs.',
                    ],
                    [
                        'prompt' => 'What document usually confirms the items and prices requested from a supplier',
                        'answer' => 'Purchase order',
                        'distractors' => ['Shelf list', 'Reference interview form', 'Call number label'],
                        'explanation' => 'A purchase order formally records what the library intends to buy from a vendor.',
                    ],
                    [
                        'prompt' => 'Why must selectors consider currency and relevance when choosing materials',
                        'answer' => 'To ensure the collection stays useful and appropriate for users',
                        'distractors' => ['To reduce catalog records permanently', 'To avoid all weeding activity', 'To replace bibliographic tools'],
                        'explanation' => 'Materials should be current and relevant so the collection continues to support user needs effectively.',
                    ],
                    [
                        'prompt' => 'Which step usually happens before a newly ordered item is paid for',
                        'answer' => 'Receiving and checking the item against the order record',
                        'distractors' => ['Assigning overdue fines to the vendor', 'Teaching a reference interview', 'Writing an abstract for the book'],
                        'explanation' => 'Libraries normally verify that the correct item was received before completing payment processing.',
                    ],
                ],
            ],
        ];
    }

    protected function normalizeSubjectLabel(mixed $subject): string
    {
        $raw = trim((string) $subject);

        if ($raw === '') {
            return 'General';
        }

        $normalized = Str::of($raw)
            ->lower()
            ->replace('&', 'and')
            ->replace('/', ' ')
            ->replace('-', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        $map = [
            'cataloging and classification' => 'Cataloging and Classification',
            'cataloguing and classification' => 'Cataloging and Classification',
            'cataloging classification' => 'Cataloging and Classification',
            'indexing and abstracting' => 'Indexing and Abstracting',
            'indexing abstracting' => 'Indexing and Abstracting',
            'information technology' => 'Information Technology',
            'it' => 'Information Technology',
            'reference services' => 'Reference Services',
            'reference service' => 'Reference Services',
            'reference' => 'Reference Services',
            'library management' => 'Library Management',
            'selection and acquisition' => 'Selection and Acquisition',
            'selection acquisition' => 'Selection and Acquisition',
            'acquisition and selection' => 'Selection and Acquisition',
            'acquisition selection' => 'Selection and Acquisition',
        ];

        if (array_key_exists($normalized, $map)) {
            return $map[$normalized];
        }

        foreach ($map as $key => $canonical) {
            if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                return $canonical;
            }
        }

        return Str::title($normalized);
    }
}
