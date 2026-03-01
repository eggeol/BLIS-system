<?php

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuthTokenCookieService;
use App\Services\ExamAttemptService;
use App\Services\ExamDeliveryModeService;
use App\Services\Library\DocxQuestionParser;
use App\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

$roleService = app(RoleService::class);
$auditLogService = app(AuditLogService::class);
$authTokenCookieService = app(AuthTokenCookieService::class);
$deliveryModeService = app(ExamDeliveryModeService::class);
$examAttemptService = app(ExamAttemptService::class);

$canManageRooms = static function (User $user) use ($roleService): bool {
    return $roleService->canManageRooms($user);
};

$isAdmin = static function (User $user) use ($roleService): bool {
    return $roleService->isAdmin($user);
};

$recordAudit = static function (
    ?User $actor,
    string $action,
    ?string $targetType = null,
    int|string|null $targetId = null,
    ?string $description = null,
    array $metadata = [],
    ?Request $request = null,
) use ($auditLogService): void {
    $auditLogService->record($actor, $action, $targetType, $targetId, $description, $metadata, $request);
};

$ensureActive = static function (Request $request) use ($authTokenCookieService) {
    $user = $request->user();

    if ($user && !$user->is_active) {
        $user->tokens()->delete();

        return $authTokenCookieService->clearTokenCookie(
            response()->json(['message' => 'Account is disabled.'], 403)
        );
    }

    return null;
};

$systemSettingDefaults = [
    'platform_name' => 'LNU LLE Platform',
    'academic_term' => 'AY 2025-2026',
    'allow_public_registration' => true,
    'maintenance_mode' => false,
    'announcement_banner' => '',
];

$systemSettingBooleanKeys = [
    'allow_public_registration',
    'maintenance_mode',
];

$findAccessibleQuestionBank = static function (User $user, int $bankId, bool $admin): ?QuestionBank {
    return QuestionBank::query()
        ->withCount('questions')
        ->whereKey($bankId)
        ->when(!$admin, fn ($query) => $query->where('created_by', $user->id))
        ->first();
};

$normalizeAnswerText = static function (?string $value): string {
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));

    return Str::lower((string) $normalized);
};

$normalizeDeliveryMode = static function (?string $mode) use ($deliveryModeService): string {
    return $deliveryModeService->normalize($mode);
};

$deliveryModeValidationModes = $deliveryModeService->validationModes();

$resolveTeacherPacedTotalItems = static function (Exam $exam) use ($deliveryModeService): int {
    return $deliveryModeService->resolveTeacherPacedTotalItems($exam);
};

$resolveTeacherPacingState = static function (int $examId, int $roomId) use ($deliveryModeService): ?array {
    return $deliveryModeService->resolveTeacherPacingState($examId, $roomId);
};

$buildTeacherPacingPayload = static function (?array $state, int $totalItems) use ($deliveryModeService): ?array {
    return $deliveryModeService->buildTeacherPacingPayload($state, $totalItems);
};

$refreshAttemptMetrics = static function (ExamAttempt $attempt, bool $finalize = false) use ($examAttemptService): ExamAttempt {
    return $examAttemptService->refreshMetrics($attempt, $finalize);
};

$autoSubmitExpiredAttempt = static function (ExamAttempt $attempt) use ($examAttemptService): ExamAttempt {
    return $examAttemptService->autoSubmitExpiredAttempt($attempt);
};

$buildAttemptPayload = static function (ExamAttempt $attempt) use ($examAttemptService): array {
    return $examAttemptService->buildAttemptPayload($attempt);
};

Route::post('/auth/register', function (Request $request) use ($recordAudit, $authTokenCookieService) {
    $registrationSetting = SystemSetting::query()
        ->where('key', 'allow_public_registration')
        ->value('value');

    $allowPublicRegistration = is_null($registrationSetting)
        ? true
        : filter_var($registrationSetting, FILTER_VALIDATE_BOOLEAN);

    if (!$allowPublicRegistration) {
        return response()->json([
            'message' => 'Public registration is currently disabled.',
        ], 403);
    }

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'student_id' => ['required', 'string', 'max:32', 'regex:/^\d{7,20}$/', 'unique:users,student_id'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'min:8', 'confirmed'],
    ]);

    $user = User::create([
        'name' => $validated['name'],
        'student_id' => trim((string) $validated['student_id']),
        'email' => $validated['email'],
        'role' => User::ROLE_STUDENT,
        'is_active' => true,
        'password' => $validated['password'],
    ]);

    $token = $user->createToken('spa')->plainTextToken;

    $recordAudit(
        $user,
        'auth.register',
        'user',
        $user->id,
        'Student account self-registered',
        ['email' => $user->email, 'student_id' => $user->student_id, 'role' => $user->role],
        $request,
    );

    $response = response()->json([
        'user' => $user,
    ], 201);

    return $authTokenCookieService->attachTokenCookie($response, $token);
});

Route::post('/auth/login', function (Request $request) use ($authTokenCookieService) {
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    $user = User::where('email', $validated['email'])->first();

    if (!$user || !Hash::check($validated['password'], $user->password)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    if (!$user->is_active) {
        return response()->json(['message' => 'Account is disabled. Contact an administrator.'], 403);
    }

    $token = $user->createToken('spa')->plainTextToken;

    $response = response()->json([
        'user' => $user,
    ]);

    return $authTokenCookieService->attachTokenCookie($response, $token);
});

Route::middleware('auth:sanctum')->group(function () use (
    $canManageRooms,
    $ensureActive,
    $isAdmin,
    $recordAudit,
    $findAccessibleQuestionBank,
    $normalizeAnswerText,
    $normalizeDeliveryMode,
    $deliveryModeValidationModes,
    $resolveTeacherPacedTotalItems,
    $resolveTeacherPacingState,
    $buildTeacherPacingPayload,
    $refreshAttemptMetrics,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload,
    $systemSettingBooleanKeys,
    $systemSettingDefaults,
    $authTokenCookieService,
) {
    Route::get('/auth/me', function (Request $request) use ($ensureActive) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        return response()->json($request->user());
    });

    Route::post('/auth/logout', function (Request $request) use ($ensureActive) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $request->user()->currentAccessToken()?->delete();

        return $authTokenCookieService->clearTokenCookie(
            response()->json(['message' => 'Logged out'])
        );
    });

    Route::get('/rooms', function (Request $request) use ($canManageRooms, $ensureActive, $isAdmin) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            $roomsQuery = Room::query()
                ->with('creator:id,name')
                ->withCount(['members', 'exams'])
                ->latest();

            if (!$isAdmin($user)) {
                $roomsQuery->where('created_by', $user->id);
            }

            $rooms = $roomsQuery->get();
        } else {
            $rooms = $user->rooms()
                ->with('creator:id,name')
                ->withCount(['members', 'exams'])
                ->orderByDesc('rooms.created_at')
                ->get();
        }

        return response()->json(['rooms' => $rooms]);
    });

    Route::get('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $isAdmin, $normalizeDeliveryMode) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } else {
            $isMember = $user->rooms()->where('rooms.id', $room->id)->exists();
            if (!$isMember) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $members = $room->members()
            ->select('users.id', 'users.name', 'users.email', 'users.student_id', 'users.role', 'users.is_active')
            ->orderBy('users.name')
            ->get();

        $assignedExams = $room->exams()
            ->select(
                'exams.id',
                'exams.title',
                'exams.subject',
                'exams.question_bank_id',
                'exams.total_items',
                'exams.duration_minutes',
                'exams.scheduled_at',
                'exams.delivery_mode',
                'exams.one_take_only',
                'exams.shuffle_questions',
                'exam_room.created_at as assigned_at'
            )
            ->orderByDesc('exam_room.created_at')
            ->get()
            ->map(function ($assignedExam) use ($normalizeDeliveryMode) {
                $assignedExam->delivery_mode = $normalizeDeliveryMode($assignedExam->delivery_mode);

                return $assignedExam;
            })
            ->values();

        $roomData = [
            'id' => $room->id,
            'name' => $room->name,
            'code' => $room->code,
            'created_by' => $room->created_by,
            'created_at' => $room->created_at,
            'updated_at' => $room->updated_at,
            'members_count' => $members->count(),
            'members' => $members,
            'assigned_exams' => $assignedExams,
        ];

        return response()->json(['room' => $roomData]);
    });

    Route::patch('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $oldName = $room->name;

        $room->update([
            'name' => $validated['name'],
        ]);

        $room->load('creator:id,name')->loadCount(['members', 'exams']);

        $recordAudit(
            $user,
            'room.update',
            'room',
            $room->id,
            'Room updated',
            ['from' => $oldName, 'to' => $room->name],
            $request,
        );

        return response()->json([
            'message' => 'Room updated',
            'room' => $room,
        ]);
    });

    Route::delete('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $roomId = $room->id;
        $roomName = $room->name;
        $roomCode = $room->code;

        $room->delete();

        $recordAudit(
            $user,
            'room.delete',
            'room',
            $roomId,
            'Room deleted',
            ['name' => $roomName, 'code' => $roomCode],
            $request,
        );

        return response()->json(['message' => 'Room deleted']);
    });

    Route::post('/rooms', function (Request $request) use ($canManageRooms, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();
        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        do {
            $code = Str::upper(Str::random(6));
        } while (Room::where('code', $code)->exists());

        $room = Room::create([
            'name' => $validated['name'],
            'code' => $code,
            'created_by' => $user->id,
        ]);

        $recordAudit(
            $user,
            'room.create',
            'room',
            $room->id,
            'Room created',
            ['name' => $room->name, 'code' => $room->code],
            $request,
        );

        return response()->json([
            'message' => 'Room created',
            'room' => $room,
        ], 201);
    });

    Route::post('/rooms/join', function (Request $request) use ($ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $room = Room::where('code', Str::upper(trim($validated['code'])))->first();
        if (!$room) {
            return response()->json(['message' => 'Room code not found'], 404);
        }

        $request->user()->rooms()->syncWithoutDetaching([$room->id]);

        $recordAudit(
            $request->user(),
            'room.join',
            'room',
            $room->id,
            'Joined room',
            ['code' => $room->code],
            $request,
        );

        return response()->json([
            'message' => 'Joined room successfully',
            'room' => $room,
        ]);
    });

    Route::delete('/rooms/{room}/leave', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $detached = $user->rooms()->detach($room->id);
        if ($detached === 0) {
            return response()->json(['message' => 'You are not enrolled in this room'], 404);
        }

        $recordAudit(
            $user,
            'room.leave',
            'room',
            $room->id,
            'Left room',
            ['code' => $room->code],
            $request,
        );

        return response()->json(['message' => 'Left room successfully']);
    });

    Route::get('/library/banks', function (Request $request) use ($canManageRooms, $ensureActive, $isAdmin) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = QuestionBank::query()
            ->with('creator:id,name')
            ->withCount('questions')
            ->latest();

        if (!$isAdmin($user)) {
            $query->where('created_by', $user->id);
        }

        return response()->json([
            'banks' => $query->get(),
        ]);
    });

    Route::post('/library/import/preview', function (Request $request) use ($canManageRooms, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:docx',
                'max:20480',
            ],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        try {
            $result = app(DocxQuestionParser::class)->parse($file->getRealPath());
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => 'Unable to parse DOCX file. Please check the file format.',
            ], 422);
        }

        if (count($result['questions']) === 0) {
            return response()->json([
                'message' => 'No valid question pattern detected from this DOCX file.',
                'preview' => [
                    'questions' => [],
                    'warnings' => $result['warnings'],
                    'answer_key_detected' => $result['answer_key_detected'],
                    'answer_key_items' => $result['answer_key_items'],
                    'total_items' => 0,
                    'source_filename' => $file->getClientOriginalName(),
                ],
            ], 422);
        }

        $recordAudit(
            $user,
            'library.import.preview',
            'docx',
            null,
            'Previewed DOCX question import',
            [
                'source_filename' => $file->getClientOriginalName(),
                'questions_detected' => count($result['questions']),
                'warnings' => count($result['warnings']),
                'answer_key_detected' => $result['answer_key_detected'],
                'answer_key_items' => $result['answer_key_items'],
            ],
            $request,
        );

        return response()->json([
            'message' => 'DOCX parsed successfully.',
            'preview' => [
                'questions' => $result['questions'],
                'warnings' => $result['warnings'],
                'answer_key_detected' => $result['answer_key_detected'],
                'answer_key_items' => $result['answer_key_items'],
                'total_items' => count($result['questions']),
                'source_filename' => $file->getClientOriginalName(),
            ],
        ]);
    });

    Route::post('/library/banks', function (Request $request) use ($canManageRooms, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'source_filename' => ['nullable', 'string', 'max:255'],
            'questions' => ['required', 'array', 'min:1', 'max:1000'],
            'questions.*.text' => ['required', 'string'],
            'questions.*.question_type' => ['nullable', Rule::in(['multiple_choice', 'true_false', 'open_ended'])],
            'questions.*.answer_label' => ['nullable', 'string', 'max:2'],
            'questions.*.answer_text' => ['nullable', 'string'],
            'questions.*.options' => ['nullable', 'array', 'max:8'],
            'questions.*.options.*.label' => ['nullable', 'string', 'max:2'],
            'questions.*.options.*.text' => ['required_with:questions.*.options', 'string'],
            'questions.*.options.*.is_correct' => ['nullable', 'boolean'],
        ]);

        $questions = collect($validated['questions'])
            ->map(function (array $question, int $index): array {
                $optionRows = collect($question['options'] ?? [])
                    ->map(function (array $option, int $optionIndex): array {
                        $defaultLabel = chr(ord('A') + min($optionIndex, 25));

                        return [
                            'sort_order' => $optionIndex + 1,
                            'label' => Str::upper(trim((string) ($option['label'] ?? $defaultLabel))),
                            'text' => trim((string) ($option['text'] ?? '')),
                            'is_correct' => (bool) ($option['is_correct'] ?? false),
                        ];
                    })
                    ->filter(fn (array $option): bool => $option['text'] !== '')
                    ->values();

                if ($optionRows->isNotEmpty()) {
                    $answerLabel = Str::upper(trim((string) ($question['answer_label'] ?? '')));
                    if ($answerLabel === '') {
                        $firstCorrectOption = $optionRows->first(fn (array $option): bool => $option['is_correct']);
                        $answerLabel = is_array($firstCorrectOption)
                            ? Str::upper(trim((string) ($firstCorrectOption['label'] ?? '')))
                            : '';
                    }

                    $optionRows = $optionRows->map(function (array $option) use ($answerLabel): array {
                        $option['is_correct'] = $answerLabel !== '' && $option['label'] === $answerLabel;

                        return $option;
                    })->values();

                    $resolvedCorrectOption = $optionRows->first(fn (array $option): bool => $option['is_correct']);
                    $answerText = is_array($resolvedCorrectOption)
                        ? ($resolvedCorrectOption['text'] ?? null)
                        : null;
                    $type = $question['question_type'] ?? 'multiple_choice';
                    $type = in_array($type, ['multiple_choice', 'true_false', 'open_ended'], true) ? $type : 'multiple_choice';
                } else {
                    $answerLabel = null;
                    $answerText = $question['answer_text'] ?? null;
                    $type = 'open_ended';
                }

                return [
                    'item_number' => $index + 1,
                    'text' => trim((string) ($question['text'] ?? '')),
                    'question_type' => $type,
                    'answer_label' => $answerLabel,
                    'answer_text' => $answerText,
                    'options' => $optionRows->all(),
                ];
            })
            ->filter(fn (array $question): bool => $question['text'] !== '')
            ->values();

        if ($questions->isEmpty()) {
            return response()->json([
                'message' => 'At least one valid question is required.',
            ], 422);
        }

        $bank = DB::transaction(function () use ($validated, $questions, $user) {
            $createdBank = QuestionBank::create([
                'title' => trim((string) $validated['title']),
                'subject' => filled($validated['subject'] ?? null)
                    ? trim((string) $validated['subject'])
                    : null,
                'source_filename' => filled($validated['source_filename'] ?? null)
                    ? trim((string) $validated['source_filename'])
                    : null,
                'total_items' => $questions->count(),
                'created_by' => $user->id,
            ]);

            foreach ($questions as $question) {
                $createdQuestion = $createdBank->questions()->create([
                    'item_number' => $question['item_number'],
                    'question_text' => $question['text'],
                    'question_type' => $question['question_type'],
                    'answer_label' => $question['answer_label'],
                    'answer_text' => $question['answer_text'],
                ]);

                foreach ($question['options'] as $option) {
                    $createdQuestion->options()->create([
                        'sort_order' => $option['sort_order'],
                        'option_label' => $option['label'],
                        'option_text' => $option['text'],
                        'is_correct' => $option['is_correct'],
                    ]);
                }
            }

            return $createdBank;
        });

        $bank->load('creator:id,name')->loadCount('questions');

        $recordAudit(
            $user,
            'library.bank.create',
            'question_bank',
            $bank->id,
            'Question bank created from parsed DOCX',
            [
                'title' => $bank->title,
                'subject' => $bank->subject,
                'total_items' => $bank->total_items,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Question bank created successfully.',
            'bank' => $bank,
        ], 201);
    });

    Route::delete('/library/banks/{bank}', function (
        Request $request,
        QuestionBank $bank
    ) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$isAdmin($user) && (int) $bank->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $questionIdSubQuery = DB::table('question_bank_questions')
            ->select('id')
            ->where('question_bank_id', $bank->id);

        $hasAttemptQuestionUsage = DB::table('exam_attempt_questions')
            ->whereIn('question_bank_question_id', $questionIdSubQuery)
            ->exists();

        $hasAttemptAnswerUsage = DB::table('exam_attempt_answers')
            ->whereIn('question_bank_question_id', function ($query) use ($bank) {
                $query->select('id')
                    ->from('question_bank_questions')
                    ->where('question_bank_id', $bank->id);
            })
            ->exists();

        if ($hasAttemptQuestionUsage || $hasAttemptAnswerUsage) {
            return response()->json([
                'message' => 'Cannot delete this question bank because it already has exam attempt records.',
            ], 422);
        }

        $linkedExamCount = $bank->exams()->count();
        $bankTitle = $bank->title;

        $bank->delete();

        $recordAudit(
            $user,
            'library.bank.delete',
            'question_bank',
            $bank->id,
            'Question bank deleted',
            [
                'title' => $bankTitle,
                'linked_exams_count' => $linkedExamCount,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Question bank deleted.',
        ]);
    });

    Route::get('/exams', function (Request $request) use ($canManageRooms, $ensureActive, $isAdmin, $normalizeDeliveryMode) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = Exam::query()
            ->with('creator:id,name')
            ->with('questionBank:id,title,subject,total_items')
            ->with('rooms:id,name,code')
            ->withCount('rooms')
            ->latest();

        if (!$isAdmin($user)) {
            $query->where('created_by', $user->id);
        }

        $exams = $query->get()->map(function (Exam $exam) use ($normalizeDeliveryMode) {
            $exam->delivery_mode = $normalizeDeliveryMode($exam->delivery_mode);

            return $exam;
        })->values();

        return response()->json([
            'exams' => $exams,
        ]);
    });

    Route::post('/exams', function (Request $request) use (
        $canManageRooms,
        $ensureActive,
        $isAdmin,
        $recordAudit,
        $findAccessibleQuestionBank,
        $normalizeDeliveryMode,
        $deliveryModeValidationModes
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'question_bank_id' => ['nullable', 'integer', 'exists:question_banks,id'],
            'total_items' => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'scheduled_at' => ['nullable', 'date'],
            'delivery_mode' => ['nullable', Rule::in($deliveryModeValidationModes)],
            'one_take_only' => ['nullable', 'boolean'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'room_ids' => ['nullable', 'array'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
        ]);

        $deliveryMode = $normalizeDeliveryMode($validated['delivery_mode'] ?? null);

        $questionBank = null;

        if (!is_null($validated['question_bank_id'] ?? null)) {
            $questionBank = $findAccessibleQuestionBank(
                $user,
                (int) $validated['question_bank_id'],
                $isAdmin($user),
            );

            if (!$questionBank) {
                return response()->json([
                    'message' => 'Selected question bank is not accessible.',
                ], 422);
            }

            if ($questionBank->questions_count < (int) $validated['total_items']) {
                return response()->json([
                    'message' => 'Selected question bank does not have enough questions for total items.',
                ], 422);
            }
        }

        $exam = Exam::create([
            'title' => $validated['title'],
            'subject' => $questionBank?->subject,
            'description' => $validated['description'] ?? null,
            'question_bank_id' => $validated['question_bank_id'] ?? null,
            'total_items' => $validated['total_items'],
            'duration_minutes' => $validated['duration_minutes'],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'delivery_mode' => $deliveryMode,
            'one_take_only' => (bool) ($validated['one_take_only'] ?? false),
            'shuffle_questions' => $deliveryMode === Exam::DELIVERY_MODE_TEACHER_PACED
                ? false
                : (bool) ($validated['shuffle_questions'] ?? false),
            'created_by' => $user->id,
        ]);

        $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

        if ($roomIds->isNotEmpty()) {
            $allowedCount = Room::query()
                ->whereIn('id', $roomIds)
                ->when(!$isAdmin($user), fn ($query) => $query->where('created_by', $user->id))
                ->count();

            if ($allowedCount !== $roomIds->count()) {
                $exam->delete();

                return response()->json([
                    'message' => 'One or more selected rooms are not accessible.',
                ], 422);
            }

            $now = now();
            $syncPayload = $roomIds->mapWithKeys(fn ($roomId) => [$roomId => [
                'assigned_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]])->all();

            $exam->rooms()->sync($syncPayload);
        }

        $exam->load(['creator:id,name', 'questionBank:id,title,subject,total_items', 'rooms:id,name,code'])->loadCount('rooms');

        $recordAudit(
            $user,
            'exam.create',
            'exam',
            $exam->id,
            'Exam created',
            [
                'title' => $exam->title,
                'scheduled_at' => $exam->scheduled_at,
                'delivery_mode' => $normalizeDeliveryMode($exam->delivery_mode),
                'question_bank_id' => $exam->question_bank_id,
                'one_take_only' => (bool) $exam->one_take_only,
                'shuffle_questions' => (bool) $exam->shuffle_questions,
                'rooms_assigned' => $exam->rooms_count,
            ],
            $request,
        );

        $exam->delivery_mode = $normalizeDeliveryMode($exam->delivery_mode);

        return response()->json([
            'message' => 'Exam created',
            'exam' => $exam,
        ], 201);
    });

    Route::patch('/exams/{exam}', function (Request $request, Exam $exam) use (
        $canManageRooms,
        $ensureActive,
        $isAdmin,
        $recordAudit,
        $findAccessibleQuestionBank,
        $normalizeDeliveryMode,
        $deliveryModeValidationModes
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$isAdmin($user) && (int) $exam->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'question_bank_id' => ['sometimes', 'nullable', 'integer', 'exists:question_banks,id'],
            'total_items' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:600'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'delivery_mode' => ['sometimes', 'required', Rule::in($deliveryModeValidationModes)],
            'one_take_only' => ['sometimes', 'boolean'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'room_ids' => ['sometimes', 'array'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
        ]);

        if (array_key_exists('delivery_mode', $validated)) {
            $validated['delivery_mode'] = $normalizeDeliveryMode((string) $validated['delivery_mode']);
        }

        $resolvedQuestionBankId = array_key_exists('question_bank_id', $validated)
            ? $validated['question_bank_id']
            : $exam->question_bank_id;
        $resolvedTotalItems = array_key_exists('total_items', $validated)
            ? (int) $validated['total_items']
            : (int) $exam->total_items;
        $resolvedQuestionBank = null;

        if (!is_null($resolvedQuestionBankId)) {
            $resolvedQuestionBank = $findAccessibleQuestionBank(
                $user,
                (int) $resolvedQuestionBankId,
                $isAdmin($user),
            );

            if (!$resolvedQuestionBank) {
                return response()->json([
                    'message' => 'Selected question bank is not accessible.',
                ], 422);
            }

            if ($resolvedQuestionBank->questions_count < $resolvedTotalItems) {
                return response()->json([
                    'message' => 'Selected question bank does not have enough questions for total items.',
                ], 422);
            }
        }

        $exam->fill($validated);
        $exam->subject = $resolvedQuestionBank?->subject;

        if ($normalizeDeliveryMode($exam->delivery_mode) === Exam::DELIVERY_MODE_TEACHER_PACED) {
            $exam->shuffle_questions = false;
        }

        $exam->save();

        if (array_key_exists('room_ids', $validated)) {
            $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

            if ($roomIds->isNotEmpty()) {
                $allowedCount = Room::query()
                    ->whereIn('id', $roomIds)
                    ->when(!$isAdmin($user), fn ($query) => $query->where('created_by', $user->id))
                    ->count();

                if ($allowedCount !== $roomIds->count()) {
                    return response()->json([
                        'message' => 'One or more selected rooms are not accessible.',
                    ], 422);
                }
            }

            $now = now();
            $syncPayload = $roomIds->mapWithKeys(fn ($roomId) => [$roomId => [
                'assigned_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]])->all();

            $exam->rooms()->sync($syncPayload);
        }

        $exam->load(['creator:id,name', 'questionBank:id,title,subject,total_items', 'rooms:id,name,code'])->loadCount('rooms');

        $recordAudit(
            $user,
            'exam.update',
            'exam',
            $exam->id,
            'Exam updated',
            [
                'title' => $exam->title,
                'scheduled_at' => $exam->scheduled_at,
                'delivery_mode' => $normalizeDeliveryMode($exam->delivery_mode),
                'question_bank_id' => $exam->question_bank_id,
                'one_take_only' => (bool) $exam->one_take_only,
                'shuffle_questions' => (bool) $exam->shuffle_questions,
                'rooms_assigned' => $exam->rooms_count,
            ],
            $request,
        );

        $exam->delivery_mode = $normalizeDeliveryMode($exam->delivery_mode);

        return response()->json([
            'message' => 'Exam updated',
            'exam' => $exam,
        ]);
    });

    Route::delete('/exams/{exam}', function (Request $request, Exam $exam) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$isAdmin($user) && (int) $exam->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $examId = $exam->id;
        $examTitle = $exam->title;

        $exam->rooms()->detach();
        $exam->delete();

        $recordAudit(
            $user,
            'exam.delete',
            'exam',
            $examId,
            'Exam deleted',
            ['title' => $examTitle],
            $request,
        );

        return response()->json(['message' => 'Exam deleted']);
    });

    Route::get('/exams/{exam}/live-dashboard', function (Request $request, Exam $exam) use (
        $canManageRooms,
        $ensureActive,
        $isAdmin,
        $normalizeDeliveryMode,
        $resolveTeacherPacedTotalItems,
        $resolveTeacherPacingState,
        $buildTeacherPacingPayload
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$isAdmin($user) && (int) $exam->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ]);

        $deliveryMode = $normalizeDeliveryMode($exam->delivery_mode);

        $room = Room::query()->find((int) $validated['room_id']);

        if (!$room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isAssigned = $exam->rooms()
            ->where('rooms.id', $room->id)
            ->exists();

        if (!$isAssigned) {
            return response()->json([
                'message' => 'This exam is not assigned to the selected room.',
            ], 422);
        }

        $teacherPacing = null;

        if ($deliveryMode === Exam::DELIVERY_MODE_TEACHER_PACED) {
            $teacherPacing = $buildTeacherPacingPayload(
                $resolveTeacherPacingState((int) $exam->id, (int) $room->id),
                $resolveTeacherPacedTotalItems($exam),
            );
        }

        $students = $room->members()
            ->select('users.id', 'users.name', 'users.email', 'users.student_id')
            ->where('users.role', User::ROLE_STUDENT)
            ->orderBy('users.name')
            ->get();

        $attemptsByStudentId = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('room_id', $room->id)
            ->whereIn('user_id', $students->pluck('id'))
            ->with([
                'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
                'attemptQuestions.question:id,question_type',
                'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
                'answers.selectedOption:id,option_label,option_text',
            ])
            ->orderByDesc('started_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($group) => $group->first());

        $maxItems = max(
            (int) $exam->total_items,
            (int) $attemptsByStudentId->max(fn (ExamAttempt $attempt) => (int) $attempt->total_items),
        );

        if ($maxItems < 1) {
            $maxItems = 1;
        }

        $itemStats = [];
        for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
            $itemStats[$itemNumber] = [
                'started_count' => 0,
                'answered_count' => 0,
                'correct_count' => 0,
            ];
        }

        $rows = $students->values()->map(function (User $student) use ($attemptsByStudentId, $maxItems, &$itemStats): array {
            /** @var ExamAttempt|null $attempt */
            $attempt = $attemptsByStudentId->get((int) $student->id);
            $itemPayloadByNumber = [];

            if ($attempt) {
                $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

                foreach ($attempt->attemptQuestions->sortBy('item_number')->values() as $attemptQuestion) {
                    $questionId = (int) $attemptQuestion->question_bank_question_id;
                    $itemNumber = (int) $attemptQuestion->item_number;
                    $answer = $answersByQuestionId->get($questionId);
                    $isAnswered = !is_null($answer);

                    $responseText = null;
                    if ($isAnswered) {
                        if ($answer->selectedOption) {
                            $optionLabel = trim((string) $answer->selectedOption->option_label);
                            $optionText = trim((string) $answer->selectedOption->option_text);
                            $responseText = filled($optionLabel)
                                ? trim($optionLabel . '. ' . $optionText)
                                : $optionText;
                        } else {
                            $responseText = trim((string) ($answer->answer_text ?? ''));
                        }

                        $responseText = $responseText === '' ? null : $responseText;
                    }

                    $itemPayloadByNumber[$itemNumber] = [
                        'item_number' => $itemNumber,
                        'question_id' => $questionId,
                        'question_type' => $attemptQuestion->question?->question_type,
                        'answered' => $isAnswered,
                        'response' => $responseText,
                        'is_correct' => $isAnswered
                            ? (is_null($answer->is_correct) ? null : (bool) $answer->is_correct)
                            : null,
                        'answered_at' => $answer?->answered_at,
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
            for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
                $items[] = $itemPayloadByNumber[$itemNumber] ?? [
                    'item_number' => $itemNumber,
                    'question_id' => null,
                    'question_type' => null,
                    'answered' => false,
                    'response' => null,
                    'is_correct' => null,
                    'answered_at' => null,
                ];
            }

            return [
                'user' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'student_id' => $student->student_id,
                ],
                'attempt' => $attempt
                    ? [
                        'id' => $attempt->id,
                        'status' => $attempt->status,
                        'answered_count' => (int) $attempt->answered_count,
                        'total_items' => (int) $attempt->total_items,
                        'score_percent' => is_null($attempt->score_percent) ? null : (float) $attempt->score_percent,
                        'started_at' => $attempt->started_at,
                        'submitted_at' => $attempt->submitted_at,
                    ]
                    : null,
                'items' => $items,
            ];
        })->all();

        $attemptsStarted = collect($rows)
            ->filter(fn (array $row) => !is_null($row['attempt'] ?? null))
            ->count();

        $attemptsSubmitted = collect($rows)
            ->filter(fn (array $row) => ($row['attempt']['status'] ?? null) === ExamAttempt::STATUS_SUBMITTED)
            ->count();

        $itemSummary = [];
        for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
            $startedCount = (int) $itemStats[$itemNumber]['started_count'];
            $answeredCount = (int) $itemStats[$itemNumber]['answered_count'];
            $correctCount = (int) $itemStats[$itemNumber]['correct_count'];

            $itemSummary[] = [
                'item_number' => $itemNumber,
                'started_count' => $startedCount,
                'answered_count' => $answeredCount,
                'correct_count' => $correctCount,
                'answered_percent' => $startedCount > 0
                    ? (int) round(($answeredCount / $startedCount) * 100)
                    : 0,
                'correct_percent' => $answeredCount > 0
                    ? (int) round(($correctCount / $answeredCount) * 100)
                    : null,
            ];
        }

        return response()->json([
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'subject' => $exam->subject,
                'total_items' => (int) $exam->total_items,
                'duration_minutes' => (int) $exam->duration_minutes,
                'scheduled_at' => $exam->scheduled_at,
                'delivery_mode' => $deliveryMode,
                'shuffle_questions' => (bool) $exam->shuffle_questions,
                'one_take_only' => (bool) $exam->one_take_only,
            ],
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ],
            'summary' => [
                'students_total' => $students->count(),
                'attempts_started' => $attemptsStarted,
                'attempts_submitted' => $attemptsSubmitted,
            ],
            'teacher_pacing' => $teacherPacing,
            'rows' => $rows,
            'item_summary' => $itemSummary,
            'generated_at' => now(),
        ]);
    });

    Route::post('/exams/{exam}/teacher-paced', function (Request $request, Exam $exam) use (
        $canManageRooms,
        $ensureActive,
        $isAdmin,
        $recordAudit,
        $normalizeDeliveryMode,
        $resolveTeacherPacedTotalItems,
        $buildTeacherPacingPayload
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$isAdmin($user) && (int) $exam->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $deliveryMode = $normalizeDeliveryMode($exam->delivery_mode);

        if ($deliveryMode !== Exam::DELIVERY_MODE_TEACHER_PACED) {
            return response()->json([
                'message' => 'Teacher pacing is only available for Teacher Paced exams.',
            ], 422);
        }

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'action' => ['required', Rule::in(['start', 'next', 'previous', 'stop'])],
        ]);

        $room = Room::query()->find((int) $validated['room_id']);

        if (!$room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isAssigned = $exam->rooms()
            ->where('rooms.id', $room->id)
            ->exists();

        if (!$isAssigned) {
            return response()->json([
                'message' => 'This exam is not assigned to the selected room.',
            ], 422);
        }

        $action = (string) $validated['action'];
        $totalItems = $resolveTeacherPacedTotalItems($exam);

        $stateRow = DB::transaction(function () use ($exam, $room, $user, $action, $totalItems) {
            $query = DB::table('exam_room_pacing_states')
                ->where('exam_id', (int) $exam->id)
                ->where('room_id', (int) $room->id)
                ->lockForUpdate();

            $row = $query->first();
            $now = now();

            if (!$row) {
                DB::table('exam_room_pacing_states')->insert([
                    'exam_id' => (int) $exam->id,
                    'room_id' => (int) $room->id,
                    'is_active' => false,
                    'current_item_number' => null,
                    'started_at' => null,
                    'updated_by' => (int) $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row = $query->first();
            }

            $isActive = (bool) ($row->is_active ?? false);
            $currentItemNumber = is_null($row->current_item_number)
                ? 1
                : (int) $row->current_item_number;
            $startedAt = $row->started_at;

            if ($action === 'next' && !$isActive) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'action' => 'Start teacher pacing before moving to the next question.',
                ]);
            }

            if ($action === 'previous' && !$isActive) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'action' => 'Start teacher pacing before moving to the previous question.',
                ]);
            }

            switch ($action) {
                case 'start':
                    $isActive = true;
                    $currentItemNumber = 1;
                    $startedAt = $now;
                    break;
                case 'next':
                    $currentItemNumber = min($totalItems, max(1, $currentItemNumber + 1));
                    break;
                case 'previous':
                    $currentItemNumber = max(1, $currentItemNumber - 1);
                    break;
                case 'stop':
                    $isActive = false;
                    $currentItemNumber = null;
                    $startedAt = null;
                    break;
            }

            DB::table('exam_room_pacing_states')
                ->where('id', (int) $row->id)
                ->update([
                    'is_active' => $isActive,
                    'current_item_number' => $isActive ? $currentItemNumber : null,
                    'started_at' => $startedAt,
                    'updated_by' => (int) $user->id,
                    'updated_at' => $now,
                ]);

            return DB::table('exam_room_pacing_states')
                ->where('id', (int) $row->id)
                ->first();
        });

        $teacherPacing = $buildTeacherPacingPayload([
            'is_active' => (bool) ($stateRow->is_active ?? false),
            'current_item_number' => is_null($stateRow->current_item_number) ? null : (int) $stateRow->current_item_number,
            'started_at' => $stateRow->started_at ?? null,
            'updated_at' => $stateRow->updated_at ?? null,
        ], $totalItems);

        $recordAudit(
            $user,
            'exam.teacher_paced.update',
            'exam',
            $exam->id,
            'Teacher paced control updated',
            [
                'room_id' => (int) $room->id,
                'action' => $action,
                'teacher_pacing' => $teacherPacing,
            ],
            $request,
        );

        return response()->json([
            'message' => match ($action) {
                'start' => 'Teacher pacing started.',
                'next' => 'Moved to the next question.',
                'previous' => 'Moved to the previous question.',
                default => 'Teacher pacing stopped.',
            },
            'teacher_pacing' => $teacherPacing,
        ]);
    });

    Route::post('/student/exams/{exam}/start', function (
        Request $request,
        Exam $exam
    ) use (
        $canManageRooms,
        $ensureActive,
        $recordAudit,
        $normalizeDeliveryMode,
        $autoSubmitExpiredAttempt,
        $buildAttemptPayload
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            return response()->json([
                'message' => 'Only student accounts can start exam attempts.',
            ], 403);
        }

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ]);

        $roomId = (int) $validated['room_id'];

        $isMember = $user->rooms()
            ->where('rooms.id', $roomId)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'message' => 'You are not a member of the selected room.',
            ], 403);
        }

        $isAssigned = $exam->rooms()
            ->where('rooms.id', $roomId)
            ->exists();

        if (!$isAssigned) {
            return response()->json([
                'message' => 'This exam is not assigned to the selected room.',
            ], 422);
        }

        if ($exam->scheduled_at && now()->lt($exam->scheduled_at)) {
            return response()->json([
                'message' => 'This exam is not yet available based on schedule.',
                'available_at' => $exam->scheduled_at,
            ], 422);
        }

        if (!$exam->question_bank_id) {
            return response()->json([
                'message' => 'This exam has no question bank configured yet.',
            ], 422);
        }

        $questionBank = QuestionBank::query()->find($exam->question_bank_id);

        if (!$questionBank) {
            return response()->json([
                'message' => 'Source question bank was not found.',
            ], 422);
        }

        $deliveryMode = $normalizeDeliveryMode($exam->delivery_mode);

        $availableQuestionCount = $questionBank->questions()->count();
        $targetItems = min((int) $exam->total_items, $availableQuestionCount);

        if ($targetItems < 1) {
            return response()->json([
                'message' => 'No questions are available for this exam.',
            ], 422);
        }

        $existingAttempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('room_id', $roomId)
            ->where('user_id', $user->id)
            ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
            ->latest('started_at')
            ->first();

        if ($existingAttempt) {
            $existingAttempt = $autoSubmitExpiredAttempt($existingAttempt);

            if ($existingAttempt->status === ExamAttempt::STATUS_IN_PROGRESS) {
                return response()->json([
                    'message' => 'Resuming your active attempt.',
                    'resumed' => true,
                    ...$buildAttemptPayload($existingAttempt),
                ]);
            }
        }

        if ((bool) $exam->one_take_only) {
            $hasSubmittedAttempt = ExamAttempt::query()
                ->where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->where('status', ExamAttempt::STATUS_SUBMITTED)
                ->exists();

            if ($hasSubmittedAttempt) {
                return response()->json([
                    'message' => 'This exam allows one attempt only and your attempt is already submitted.',
                ], 422);
            }
        }

        $questionBankId = (int) $questionBank->id;
        $questionQuery = DB::table('question_bank_questions')
            ->where('question_bank_id', $questionBankId);

        if ((bool) $exam->shuffle_questions && $deliveryMode !== Exam::DELIVERY_MODE_TEACHER_PACED) {
            $questionQuery->inRandomOrder();
        } else {
            $questionQuery
                ->orderBy('item_number')
                ->orderBy('id');
        }

        $questionIds = $questionQuery
            ->limit($targetItems)
            ->pluck('id')
            ->values()
            ->all();
        $actualTotalItems = count($questionIds);

        if ($actualTotalItems < 1) {
            return response()->json([
                'message' => 'No questions are available for this exam.',
            ], 422);
        }

        $durationMinutes = max(1, (int) $exam->duration_minutes);
        $startedAt = now();

        $attempt = DB::transaction(function () use (
            $exam,
            $roomId,
            $user,
            $questionIds,
            $actualTotalItems,
            $durationMinutes,
            $startedAt
        ) {
            $createdAttempt = ExamAttempt::create([
                'exam_id' => $exam->id,
                'room_id' => $roomId,
                'user_id' => $user->id,
                'status' => ExamAttempt::STATUS_IN_PROGRESS,
                'total_items' => $actualTotalItems,
                'duration_minutes' => $durationMinutes,
                'answered_count' => 0,
                'correct_answers' => 0,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addMinutes($durationMinutes),
            ]);

            $rows = [];

            foreach ($questionIds as $index => $questionId) {
                $rows[] = [
                    'exam_attempt_id' => $createdAttempt->id,
                    'question_bank_question_id' => $questionId,
                    'item_number' => $index + 1,
                    'is_bookmarked' => false,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ];
            }

            ExamAttemptQuestion::insert($rows);

            return $createdAttempt->refresh();
        });

        $recordAudit(
            $user,
            'exam.attempt.start',
            'exam_attempt',
            $attempt->id,
            'Student started an exam attempt',
            [
                'exam_id' => $exam->id,
                'room_id' => $roomId,
                'total_items' => $attempt->total_items,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Exam attempt started.',
            'resumed' => false,
            ...$buildAttemptPayload($attempt),
        ], 201);
    });

    Route::get('/student/exam-attempts/{attempt}', function (
        Request $request,
        ExamAttempt $attempt
    ) use (
        $canManageRooms,
        $ensureActive,
        $autoSubmitExpiredAttempt,
        $buildAttemptPayload
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $autoSubmitExpiredAttempt($attempt);

        return response()->json($buildAttemptPayload($attempt));
    });

    Route::patch('/student/exam-attempts/{attempt}/answers', function (
        Request $request,
        ExamAttempt $attempt
    ) use (
        $canManageRooms,
        $ensureActive,
        $normalizeAnswerText,
        $normalizeDeliveryMode,
        $resolveTeacherPacingState,
        $refreshAttemptMetrics,
        $autoSubmitExpiredAttempt,
        $buildAttemptPayload
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $autoSubmitExpiredAttempt($attempt);

        if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'This attempt is already submitted.',
                ...$buildAttemptPayload($attempt),
            ], 422);
        }

        $attempt->loadMissing('exam:id,delivery_mode');
        $deliveryMode = $normalizeDeliveryMode($attempt->exam?->delivery_mode);

        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:question_bank_questions,id'],
            'selected_option_id' => ['nullable', 'integer', 'exists:question_bank_options,id'],
            'answer_text' => ['nullable', 'string'],
        ]);

        $questionId = (int) $validated['question_id'];

        if ($deliveryMode === Exam::DELIVERY_MODE_INSTANT_FEEDBACK) {
            $nextRequired = DB::table('exam_attempt_questions as attempt_questions')
                ->leftJoin('exam_attempt_answers as answers', function ($join) use ($attempt) {
                    $join
                        ->on('answers.question_bank_question_id', '=', 'attempt_questions.question_bank_question_id')
                        ->where('answers.exam_attempt_id', '=', (int) $attempt->id);
                })
                ->where('attempt_questions.exam_attempt_id', (int) $attempt->id)
                ->whereNull('answers.id')
                ->orderBy('attempt_questions.item_number')
                ->select('attempt_questions.question_bank_question_id', 'attempt_questions.item_number')
                ->first();

            if (!$nextRequired) {
                return response()->json([
                    'message' => 'All questions are already answered. Submit your attempt to finish.',
                    ...$buildAttemptPayload($attempt),
                ], 422);
            }

            if ((int) $nextRequired->question_bank_question_id !== $questionId) {
                return response()->json([
                    'message' => 'Answer questions in order for Instant Feedback mode.',
                    'next_required_item_number' => (int) $nextRequired->item_number,
                    ...$buildAttemptPayload($attempt),
                ], 422);
            }
        }

        $attemptQuestion = $attempt->attemptQuestions()
            ->where('question_bank_question_id', $questionId)
            ->first();

        if (!$attemptQuestion) {
            return response()->json([
                'message' => 'The selected question is not part of this attempt.',
            ], 422);
        }

        $question = $attemptQuestion->question()
            ->with('options:id,question_bank_question_id,option_label,option_text,is_correct')
            ->first();

        if (!$question) {
            return response()->json([
                'message' => 'Question not found.',
            ], 422);
        }

        if ($deliveryMode === Exam::DELIVERY_MODE_TEACHER_PACED) {
            $teacherPacing = $resolveTeacherPacingState((int) $attempt->exam_id, (int) $attempt->room_id);

            if (!($teacherPacing['is_active'] ?? false)) {
                return response()->json([
                    'message' => 'Wait for your teacher to start paced mode.',
                    ...$buildAttemptPayload($attempt),
                ], 422);
            }

            $currentItemNumber = (int) ($teacherPacing['current_item_number'] ?? 0);

            if ($currentItemNumber < 1 || (int) $attemptQuestion->item_number !== $currentItemNumber) {
                return response()->json([
                    'message' => 'You can only answer the question currently opened by your teacher.',
                    ...$buildAttemptPayload($attempt),
                ], 422);
            }
        }

        $selectedOptionId = is_null($validated['selected_option_id'] ?? null)
            ? null
            : (int) $validated['selected_option_id'];
        $answerText = array_key_exists('answer_text', $validated)
            ? trim((string) $validated['answer_text'])
            : null;
        $answerText = $answerText === '' ? null : $answerText;

        if ($question->question_type === 'open_ended') {
            if (is_null($answerText)) {
                ExamAttemptAnswer::query()
                    ->where('exam_attempt_id', $attempt->id)
                    ->where('question_bank_question_id', $questionId)
                    ->delete();
            } else {
                $normalizedSubmitted = $normalizeAnswerText($answerText);
                $normalizedExpected = $normalizeAnswerText($question->answer_text);
                $normalizedAnswerLabel = $normalizeAnswerText($question->answer_label);

                $isCorrect = false;

                if ($normalizedExpected !== '' && $normalizedSubmitted === $normalizedExpected) {
                    $isCorrect = true;
                } elseif ($normalizedAnswerLabel !== '' && $normalizedSubmitted === $normalizedAnswerLabel) {
                    $isCorrect = true;
                }

                ExamAttemptAnswer::updateOrCreate(
                    [
                        'exam_attempt_id' => $attempt->id,
                        'question_bank_question_id' => $questionId,
                    ],
                    [
                        'question_bank_option_id' => null,
                        'answer_text' => $answerText,
                        'is_correct' => $isCorrect,
                        'answered_at' => now(),
                    ],
                );
            }
        } else {
            if (is_null($selectedOptionId)) {
                ExamAttemptAnswer::query()
                    ->where('exam_attempt_id', $attempt->id)
                    ->where('question_bank_question_id', $questionId)
                    ->delete();
            } else {
                $selectedOption = $question->options
                    ->firstWhere('id', $selectedOptionId);

                if (!$selectedOption) {
                    return response()->json([
                        'message' => 'Selected option is not valid for this question.',
                    ], 422);
                }

                ExamAttemptAnswer::updateOrCreate(
                    [
                        'exam_attempt_id' => $attempt->id,
                        'question_bank_question_id' => $questionId,
                    ],
                    [
                        'question_bank_option_id' => $selectedOption->id,
                        'answer_text' => $selectedOption->option_text,
                        'is_correct' => (bool) $selectedOption->is_correct,
                        'answered_at' => now(),
                    ],
                );
            }
        }

        $attempt = $refreshAttemptMetrics($attempt->refresh(), false);
        $attempt = $autoSubmitExpiredAttempt($attempt);

        return response()->json([
            'message' => 'Answer saved.',
            ...$buildAttemptPayload($attempt),
        ]);
    });

    Route::patch('/student/exam-attempts/{attempt}/questions/{questionId}/bookmark', function (
        Request $request,
        ExamAttempt $attempt,
        int $questionId
    ) use (
        $canManageRooms,
        $ensureActive,
        $normalizeDeliveryMode,
        $autoSubmitExpiredAttempt,
        $buildAttemptPayload
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $autoSubmitExpiredAttempt($attempt);

        if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'This attempt is already submitted.',
                ...$buildAttemptPayload($attempt),
            ], 422);
        }

        $attempt->loadMissing('exam:id,delivery_mode');
        $deliveryMode = $normalizeDeliveryMode($attempt->exam?->delivery_mode);

        if ($deliveryMode !== Exam::DELIVERY_MODE_OPEN_NAVIGATION) {
            return response()->json([
                'message' => 'Bookmarking is only available in Open Navigation mode.',
                ...$buildAttemptPayload($attempt),
            ], 422);
        }

        $validated = $request->validate([
            'is_bookmarked' => ['required', 'boolean'],
        ]);

        $attemptQuestion = $attempt->attemptQuestions()
            ->where('question_bank_question_id', $questionId)
            ->first();

        if (!$attemptQuestion) {
            return response()->json([
                'message' => 'The selected question is not part of this attempt.',
            ], 422);
        }

        $attemptQuestion->is_bookmarked = (bool) $validated['is_bookmarked'];
        $attemptQuestion->save();

        return response()->json([
            'message' => $attemptQuestion->is_bookmarked ? 'Question bookmarked.' : 'Bookmark removed.',
            ...$buildAttemptPayload($attempt->refresh()),
        ]);
    });

    Route::post('/student/exam-attempts/{attempt}/submit', function (
        Request $request,
        ExamAttempt $attempt
    ) use (
        $canManageRooms,
        $ensureActive,
        $recordAudit,
        $normalizeDeliveryMode,
        $refreshAttemptMetrics,
        $autoSubmitExpiredAttempt,
        $buildAttemptPayload
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $autoSubmitExpiredAttempt($attempt);

        if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'Attempt already submitted.',
                ...$buildAttemptPayload($attempt),
            ]);
        }

        $attempt->loadMissing('exam:id,delivery_mode');
        $deliveryMode = $normalizeDeliveryMode($attempt->exam?->delivery_mode);

        if (
            $deliveryMode === Exam::DELIVERY_MODE_INSTANT_FEEDBACK
            && (int) $attempt->answered_count < (int) $attempt->total_items
        ) {
            return response()->json([
                'message' => 'Instant Feedback mode requires all questions to be answered before submitting.',
                ...$buildAttemptPayload($attempt),
            ], 422);
        }

        $attempt = $refreshAttemptMetrics($attempt, true);

        $recordAudit(
            $user,
            'exam.attempt.submit',
            'exam_attempt',
            $attempt->id,
            'Student submitted an exam attempt',
            [
                'exam_id' => $attempt->exam_id,
                'room_id' => $attempt->room_id,
                'score_percent' => $attempt->score_percent,
                'correct_answers' => $attempt->correct_answers,
                'total_items' => $attempt->total_items,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Attempt submitted successfully.',
            ...$buildAttemptPayload($attempt),
        ]);
    });

    Route::get('/reports/overview', function (Request $request) use ($canManageRooms, $ensureActive, $isAdmin) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($isAdmin($user)) {
            $metrics = [
                'total_users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'inactive_users' => User::where('is_active', false)->count(),
                'students' => User::where('role', User::ROLE_STUDENT)->count(),
                'staff' => User::where('role', User::ROLE_STAFF_MASTER_EXAMINER)->count(),
                'admins' => User::where('role', User::ROLE_ADMIN)->count(),
                'total_rooms' => Room::count(),
                'total_exams' => Exam::count(),
                'exam_assignments' => DB::table('exam_room')->count(),
            ];

            $recentActivity = AuditLog::query()
                ->with('actor:id,name,role')
                ->latest()
                ->limit(14)
                ->get();
        } else {
            $staffRooms = Room::query()->where('created_by', $user->id);
            $staffExams = Exam::query()->where('created_by', $user->id);

            $metrics = [
                'managed_rooms' => $staffRooms->count(),
                'managed_exams' => $staffExams->count(),
                'students_enrolled' => DB::table('room_user')
                    ->join('rooms', 'rooms.id', '=', 'room_user.room_id')
                    ->where('rooms.created_by', $user->id)
                    ->distinct('room_user.user_id')
                    ->count('room_user.user_id'),
                'exam_assignments' => DB::table('exam_room')
                    ->join('exams', 'exams.id', '=', 'exam_room.exam_id')
                    ->where('exams.created_by', $user->id)
                    ->count(),
            ];

            $recentActivity = AuditLog::query()
                ->with('actor:id,name,role')
                ->where('actor_id', $user->id)
                ->latest()
                ->limit(14)
                ->get();
        }

        return response()->json([
            'metrics' => $metrics,
            'recent_activity' => $recentActivity,
        ]);
    });

    Route::get('/settings/system', function (Request $request) use (
        $canManageRooms,
        $ensureActive,
        $isAdmin,
        $systemSettingBooleanKeys,
        $systemSettingDefaults
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $stored = SystemSetting::query()
            ->whereIn('key', array_keys($systemSettingDefaults))
            ->pluck('value', 'key')
            ->all();

        $settings = [];

        foreach ($systemSettingDefaults as $key => $defaultValue) {
            $raw = $stored[$key] ?? $defaultValue;

            if (in_array($key, $systemSettingBooleanKeys, true)) {
                $settings[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
                continue;
            }

            $settings[$key] = $raw;
        }

        return response()->json([
            'settings' => $settings,
            'can_edit' => $isAdmin($user),
        ]);
    });

    Route::put('/settings/system', function (Request $request) use (
        $ensureActive,
        $isAdmin,
        $recordAudit,
        $systemSettingDefaults
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'platform_name' => ['sometimes', 'required', 'string', 'max:255'],
            'academic_term' => ['sometimes', 'required', 'string', 'max:255'],
            'allow_public_registration' => ['sometimes', 'required', 'boolean'],
            'maintenance_mode' => ['sometimes', 'required', 'boolean'],
            'announcement_banner' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        foreach ($validated as $key => $value) {
            if (!array_key_exists($key, $systemSettingDefaults)) {
                continue;
            }

            SystemSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    'updated_by' => $user->id,
                ],
            );
        }

        $recordAudit(
            $user,
            'settings.update',
            'system_settings',
            null,
            'System settings updated',
            ['keys' => array_keys($validated)],
            $request,
        );

        return response()->json([
            'message' => 'Settings updated',
        ]);
    });

    Route::middleware([])->prefix('/admin')->group(function () use ($ensureActive, $isAdmin, $recordAudit) {
        Route::get('/users', function (Request $request) use ($ensureActive, $isAdmin) {
            if ($inactiveResponse = $ensureActive($request)) {
                return $inactiveResponse;
            }

            $actor = $request->user();
            if (!$isAdmin($actor)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $validated = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'role' => ['nullable', Rule::in(User::ROLES)],
                'status' => ['nullable', Rule::in(['active', 'inactive'])],
            ]);

            $users = User::query()
                ->select('id', 'name', 'email', 'student_id', 'role', 'is_active', 'created_at', 'updated_at')
                ->when(
                    filled($validated['search'] ?? null),
                    fn ($query) => $query->where(function ($inner) use ($validated) {
                        $search = trim((string) $validated['search']);

                        $inner->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%");
                    })
                )
                ->when(
                    filled($validated['role'] ?? null),
                    fn ($query) => $query->where('role', $validated['role'])
                )
                ->when(
                    filled($validated['status'] ?? null),
                    fn ($query) => $query->where('is_active', $validated['status'] === 'active')
                )
                ->orderByDesc('id')
                ->get();

            return response()->json([
                'users' => $users,
            ]);
        });

        Route::post('/users', function (Request $request) use ($ensureActive, $isAdmin, $recordAudit) {
            if ($inactiveResponse = $ensureActive($request)) {
                return $inactiveResponse;
            }

            $actor = $request->user();
            if (!$isAdmin($actor)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'student_id' => [
                    Rule::requiredIf(fn () => $request->input('role') === User::ROLE_STUDENT),
                    'nullable',
                    'string',
                    'max:32',
                    'regex:/^\d{7,20}$/',
                    'unique:users,student_id',
                ],
                'role' => ['required', Rule::in(User::ROLES)],
                'password' => ['required', 'string', 'min:8'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $normalizedStudentId = filled($validated['student_id'] ?? null)
                ? trim((string) $validated['student_id'])
                : null;

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'student_id' => $validated['role'] === User::ROLE_STUDENT ? $normalizedStudentId : null,
                'role' => $validated['role'],
                'is_active' => $validated['is_active'] ?? true,
                'password' => $validated['password'],
            ]);

            $recordAudit(
                $actor,
                'admin.user.create',
                'user',
                $user->id,
                'Admin created user account',
                [
                    'email' => $user->email,
                    'student_id' => $user->student_id,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                ],
                $request,
            );

            return response()->json([
                'message' => 'User created',
                'user' => $user,
            ], 201);
        });

        Route::patch('/users/{user}', function (Request $request, User $user) use ($ensureActive, $isAdmin, $recordAudit) {
            if ($inactiveResponse = $ensureActive($request)) {
                return $inactiveResponse;
            }

            $actor = $request->user();
            if (!$isAdmin($actor)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $validated = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'student_id' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\d{7,20}$/', Rule::unique('users', 'student_id')->ignore($user->id)],
                'role' => ['sometimes', 'required', Rule::in(User::ROLES)],
                'is_active' => ['sometimes', 'required', 'boolean'],
                'password' => ['sometimes', 'required', 'string', 'min:8'],
            ]);

            if (array_key_exists('student_id', $validated)) {
                $validated['student_id'] = filled($validated['student_id'])
                    ? trim((string) $validated['student_id'])
                    : null;
            }

            $resolvedRole = array_key_exists('role', $validated)
                ? $validated['role']
                : $user->role;
            $resolvedStudentId = array_key_exists('student_id', $validated)
                ? $validated['student_id']
                : $user->student_id;

            if ($resolvedRole === User::ROLE_STUDENT && blank($resolvedStudentId)) {
                return response()->json([
                    'message' => 'Student ID is required for student accounts.',
                ], 422);
            }

            if ($resolvedRole !== User::ROLE_STUDENT) {
                $validated['student_id'] = null;
            }

            if ((int) $actor->id === (int) $user->id) {
                if (($validated['is_active'] ?? true) === false) {
                    return response()->json([
                        'message' => 'You cannot disable your own account.',
                    ], 422);
                }

                if (array_key_exists('role', $validated) && $validated['role'] !== User::ROLE_ADMIN) {
                    return response()->json([
                        'message' => 'You cannot remove your own admin role.',
                    ], 422);
                }
            }

            $user->fill(collect($validated)->except(['password'])->all());

            if (array_key_exists('password', $validated)) {
                $user->password = $validated['password'];
            }

            $user->save();

            if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
                $user->tokens()->delete();
            }

            $recordAudit(
                $actor,
                'admin.user.update',
                'user',
                $user->id,
                'Admin updated user account',
                [
                    'role' => $user->role,
                    'student_id' => $user->student_id,
                    'is_active' => $user->is_active,
                    'updated_fields' => array_keys($validated),
                ],
                $request,
            );

            return response()->json([
                'message' => 'User updated',
                'user' => $user,
            ]);
        });

        Route::get('/rooms', function (Request $request) use ($ensureActive, $isAdmin) {
            if ($inactiveResponse = $ensureActive($request)) {
                return $inactiveResponse;
            }

            if (!$isAdmin($request->user())) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $rooms = Room::query()
                ->with('creator:id,name,email,role')
                ->withCount(['members', 'exams'])
                ->latest()
                ->get();

            return response()->json([
                'rooms' => $rooms,
            ]);
        });

        Route::get('/audit-logs', function (Request $request) use ($ensureActive, $isAdmin) {
            if ($inactiveResponse = $ensureActive($request)) {
                return $inactiveResponse;
            }

            if (!$isAdmin($request->user())) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $limit = (int) $request->query('limit', 80);
            $limit = max(1, min(200, $limit));

            $logs = AuditLog::query()
                ->with('actor:id,name,email,role')
                ->latest()
                ->limit($limit)
                ->get();

            return response()->json([
                'logs' => $logs,
            ]);
        });
    });
});
