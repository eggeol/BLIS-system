<?php

use App\Mail\StudentReportMail;
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
use App\Services\ReviewBotService;
use App\Services\ReportExportService;
use App\Services\RoleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$roleService = app(RoleService::class);
$auditLogService = app(AuditLogService::class);
$authTokenCookieService = app(AuthTokenCookieService::class);
$deliveryModeService = app(ExamDeliveryModeService::class);
$examAttemptService = app(ExamAttemptService::class);
$reportExportService = app(ReportExportService::class);
$reviewBotService = app(ReviewBotService::class);

$canManageRooms = static function (User $user) use ($roleService): bool {
    return $roleService->canManageRooms($user);
};

$isAdmin = static function (User $user) use ($roleService): bool {
    return $roleService->isAdmin($user);
};

$isStaffMasterExaminer = static function (User $user) use ($roleService): bool {
    return $roleService->isStaffMasterExaminer($user);
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

$ensureTeacherReportAccess = static function (
    Request $request,
    Exam $exam,
    Room $room
) use ($ensureActive, $isStaffMasterExaminer): User|JsonResponse {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $exam->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $room->created_by !== (int) $user->id) {
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

    return $user;
};

$defaultTermsOfUseText = <<<'TEXT'
1. Acceptable Use
Use the platform only for official academic review and examination activities. Sharing accounts, impersonating users, or attempting to bypass exam controls is prohibited.

2. Account Responsibility
You are responsible for keeping your credentials secure. Actions performed using your account are treated as your own unless officially reported as compromised.

3. Academic Integrity
Cheating, unauthorized collaboration, copying exam content, or distributing question material may result in account suspension and disciplinary action.

4. Availability and Changes
System features may be updated or temporarily unavailable for maintenance. Administrators may enforce policy updates to keep the platform compliant and secure.

5. Enforcement
Violations can lead to investigation, removal of access, and formal reporting to relevant academic authorities.
TEXT;

$defaultPrivacyPolicyText = <<<'TEXT'
1. Data We Collect
The platform stores identity details (name, student ID, email), role information, room enrollments, and exam performance records required for academic operations.

2. How Data Is Used
Collected data is used to authenticate users, manage rooms and exams, generate reports, and maintain audit logs for security and accountability.

3. Data Access
Access to personal and exam-related data is role-based. Authorized staff and administrators can access only the data needed for their responsibilities.

4. Data Retention
Records are retained based on institutional requirements for academic continuity, reporting, and account recovery workflows.

5. Security and Contact
Reasonable safeguards are applied to protect stored data. Report privacy concerns or account issues to the institution administrators for assistance.
TEXT;

$systemSettingDefaults = [
    'platform_name' => 'LNU LLE Platform',
    'academic_term' => 'AY 2025-2026',
    'allow_public_registration' => true,
    'maintenance_mode' => false,
    'announcement_banner' => '',
    'terms_of_use_text' => $defaultTermsOfUseText,
    'privacy_policy_text' => $defaultPrivacyPolicyText,
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

$normalizeQuestionBankIds = static function (array $bankIds): array {
    return collect($bankIds)
        ->map(fn ($id) => (int) $id)
        ->filter(fn ($id) => $id > 0)
        ->unique()
        ->values()
        ->all();
};

$resolveExamQuestionBankIds = static function (array $validated, ?Exam $exam = null) use ($normalizeQuestionBankIds): array {
    if (array_key_exists('question_bank_ids', $validated)) {
        return $normalizeQuestionBankIds($validated['question_bank_ids'] ?? []);
    }

    if (array_key_exists('question_bank_id', $validated)) {
        return !is_null($validated['question_bank_id'])
            ? [(int) $validated['question_bank_id']]
            : [];
    }

    return $exam ? $exam->resolvedQuestionBankIds() : [];
};

$findAccessibleQuestionBanks = static function (User $user, array $bankIds, bool $admin) use ($normalizeQuestionBankIds) {
    $normalizedBankIds = $normalizeQuestionBankIds($bankIds);

    if ($normalizedBankIds === []) {
        return collect();
    }

    $orderLookup = array_flip($normalizedBankIds);

    return QuestionBank::query()
        ->withCount('questions')
        ->whereIn('id', $normalizedBankIds)
        ->when(!$admin, fn ($query) => $query->where('created_by', $user->id))
        ->get()
        ->sortBy(fn (QuestionBank $bank) => $orderLookup[(int) $bank->id] ?? PHP_INT_MAX)
        ->values();
};

$normalizeAnswerText = static function (?string $value): string {
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));

    return Str::lower((string) $normalized);
};

$normalizeDeliveryMode = static function (?string $mode) use ($deliveryModeService): string {
    return $deliveryModeService->normalize($mode);
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
        'first_name' => ['required', 'string', 'max:255'],
        'middle_name' => ['nullable', 'string', 'max:255'],
        'last_name' => ['required', 'string', 'max:255'],
        'student_id' => ['required', 'string', 'max:32', 'regex:/^\d{7,20}$/', 'unique:users,student_id'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'min:8', 'confirmed'],
        'agreed' => ['required', 'accepted'],
    ]);

    $firstName = Str::squish((string) $validated['first_name']);
    $middleName = array_key_exists('middle_name', $validated)
        ? Str::squish((string) $validated['middle_name'])
        : '';
    $lastName = Str::squish((string) $validated['last_name']);
    $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));

    $user = User::create([
        'name' => $fullName,
        'first_name' => $firstName,
        'middle_name' => $middleName !== '' ? $middleName : null,
        'last_name' => $lastName,
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
        'identifier' => ['required', 'string'],
        'password' => ['required'],
    ]);

    $user = User::where('email', $validated['identifier'])
        ->orWhere('student_id', $validated['identifier'])
        ->first();

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

Route::get('/settings/public/legal', function () use ($systemSettingDefaults) {
    $legalKeys = ['terms_of_use_text', 'privacy_policy_text'];

    $stored = SystemSetting::query()
        ->whereIn('key', $legalKeys)
        ->pluck('value', 'key')
        ->all();

    $settings = [];

    foreach ($legalKeys as $key) {
        $storedValue = $stored[$key] ?? null;
        $settings[$key] = is_string($storedValue) && trim($storedValue) !== ''
            ? $storedValue
            : (string) $systemSettingDefaults[$key];
    }

    $lastUpdatedAt = SystemSetting::query()
        ->whereIn('key', $legalKeys)
        ->max('updated_at');

    return response()->json([
        'settings' => $settings,
        'last_updated_at' => $lastUpdatedAt,
    ]);
});

Route::middleware('auth:sanctum')->group(function () use (
    $canManageRooms,
    $ensureActive,
    $isAdmin,
    $isStaffMasterExaminer,
    $recordAudit,
    $findAccessibleQuestionBank,
    $resolveExamQuestionBankIds,
    $findAccessibleQuestionBanks,
    $normalizeAnswerText,
    $normalizeDeliveryMode,
    $refreshAttemptMetrics,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload,
    $systemSettingBooleanKeys,
    $systemSettingDefaults,
    $authTokenCookieService,
    $ensureTeacherReportAccess,
    $reportExportService,
    $reviewBotService,
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

        $assignedExams = collect();

        if (!$isAdmin($user)) {
            $assignedExams = $room->exams()
                ->with([
                    'questionBank:id,title,subject,total_items',
                    'questionBanks:id,title,subject,total_items',
                ])
                ->select(
                    'exams.id',
                    'exams.title',
                    'exams.subject',
                    'exams.question_bank_id',
                    'exams.total_items',
                    'exams.duration_minutes',
                    'exams.scheduled_at',
                    'exams.schedule_start_at',
                    'exams.schedule_end_at',
                    'exams.delivery_mode',
                    'exams.one_take_only',
                    'exams.shuffle_questions',
                    'exam_room.created_at as assigned_at'
                )
                ->orderByDesc('exam_room.created_at')
                ->get()
                ->map(function ($assignedExam) use ($normalizeDeliveryMode) {
                    $assignedExam->delivery_mode = $normalizeDeliveryMode($assignedExam->delivery_mode);
                    $assignedExam->question_bank_ids = $assignedExam->resolvedQuestionBankIds();

                    return $assignedExam;
                })
                ->values();
        }

        if (!$canManageRooms($user) && $assignedExams->isNotEmpty()) {
            $examIds = $assignedExams
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $attemptsByExamId = ExamAttempt::query()
                ->whereIn('exam_id', $examIds)
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    ExamAttempt::STATUS_IN_PROGRESS,
                    ExamAttempt::STATUS_SUBMITTED,
                ])
                ->select('id', 'exam_id', 'room_id', 'status', 'submitted_at', 'started_at')
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('exam_id');

            $assignedExams = $assignedExams
                ->map(function ($assignedExam) use ($attemptsByExamId, $room) {
                    $attempts = $attemptsByExamId->get((int) $assignedExam->id, collect());
                    $submittedAttempts = $attempts->filter(
                        fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED
                    );

                    $inProgressAttempt = $attempts->first(
                        fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_IN_PROGRESS
                            && (int) $attempt->room_id === (int) $room->id
                    );

                    $submittedAttempt = $submittedAttempts->first();
                    $submittedAttemptCount = (int) $submittedAttempts->count();
                    $maxAllowedAttempts = (bool) $assignedExam->one_take_only ? 1 : 2;
                    $remainingAttempts = max(0, $maxAllowedAttempts - $submittedAttemptCount);

                    $attemptState = 'not_started';
                    $latestAttemptId = null;

                    if ($inProgressAttempt) {
                        $attemptState = 'in_progress';
                        $latestAttemptId = (int) $inProgressAttempt->id;
                    } elseif ($submittedAttempt) {
                        $attemptState = 'submitted';
                        $latestAttemptId = (int) $submittedAttempt->id;
                    }

                    $assignedExam->student_attempt_state = $attemptState;
                    $assignedExam->student_attempt_id = $latestAttemptId;
                    $assignedExam->student_submitted_at = $submittedAttempt?->submitted_at;
                    $assignedExam->student_submitted_attempts = $submittedAttemptCount;
                    $assignedExam->student_max_attempts = $maxAllowedAttempts;
                    $assignedExam->student_attempts_remaining = $remainingAttempts;
                    $assignedExam->student_can_start_attempt = $remainingAttempts > 0;

                    return $assignedExam;
                })
                ->values();
        }

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

    Route::post('/rooms', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();
        if (!$isStaffMasterExaminer($user)) {
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

    Route::delete('/rooms/{room}/members/{member}', function (
        Request $request,
        Room $room,
        User $member
    ) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
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

        if ($member->role !== User::ROLE_STUDENT) {
            return response()->json([
                'message' => 'Only student members can be removed from rooms.',
            ], 422);
        }

        $detached = $room->members()->detach($member->id);
        if ($detached === 0) {
            return response()->json([
                'message' => 'Student is not enrolled in this room.',
            ], 404);
        }

        $recordAudit(
            $user,
            'room.member.remove',
            'room',
            $room->id,
            'Removed student from room',
            [
                'room_id' => $room->id,
                'room_code' => $room->code,
                'student_id' => $member->id,
                'student_email' => $member->email,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Student removed from room.',
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

    Route::get('/library/banks', function (Request $request) use ($isStaffMasterExaminer, $ensureActive) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = QuestionBank::query()
            ->with('creator:id,name')
            ->withCount('questions')
            ->where('created_by', $user->id)
            ->latest();

        return response()->json([
            'banks' => $query->get(),
        ]);
    });

    Route::post('/library/import/preview', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!$value instanceof \Illuminate\Http\UploadedFile) {
                        $fail('The uploaded file is invalid.');
                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    if ($extension === 'doc') {
                        $fail('Legacy .doc files are not supported. Open the file in Word or Google Docs and save it as .docx, then try again.');
                        return;
                    }

                    if ($extension !== 'docx') {
                        $fail('Please upload a valid .docx file.');
                        return;
                    }

                    $realPath = $value->getRealPath();
                    if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
                        $fail('The uploaded .docx file could not be read. Please upload it again.');
                        return;
                    }

                    $zip = new ZipArchive();
                    if ($zip->open($realPath) !== true) {
                        $fail('The uploaded .docx file is invalid or corrupted. Save/export it again as .docx and try again.');
                        return;
                    }

                    $requiredEntries = [
                        '[Content_Types].xml',
                        '_rels/.rels',
                        'word/document.xml',
                    ];

                    foreach ($requiredEntries as $entry) {
                        if ($zip->locateName($entry) === false) {
                            $zip->close();
                            $fail('The uploaded .docx file is invalid or corrupted. Save/export it again as .docx and try again.');
                            return;
                        }
                    }

                    $zip->close();
                },
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

    Route::post('/library/banks', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
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
    ) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $bank->created_by !== (int) $user->id) {
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

    Route::get('/student/review-bot/subjects', function (Request $request) use ($ensureActive, $reviewBotService) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($user->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'subjects' => $reviewBotService->listSubjects(),
        ]);
    });

    Route::post('/student/review-bot/generate', function (
        Request $request
    ) use (
        $ensureActive,
        $reviewBotService,
        $recordAudit
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if ($user->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'question_count' => ['required', 'integer', 'min:3', 'max:30'],
            'subjects' => ['required', 'array', 'min:1', 'max:8'],
            'subjects.*' => ['required', 'string', 'max:255'],
        ]);

        try {
            $reviewSet = $reviewBotService->generateQuiz(
                $validated['subjects'],
                (int) $validated['question_count'],
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $recordAudit(
            $user,
            'review-bot.generate',
            'review_bot',
            null,
            'Generated a Review Bot practice set',
            [
                'question_count' => (int) $validated['question_count'],
                'subjects' => $reviewSet['subjects'],
                'generator' => $reviewSet['generator'],
            ],
            $request,
        );

        return response()->json([
            'message' => $reviewSet['generator'] === 'ai'
                ? 'AI review set generated successfully.'
                : 'Review set generated from the teacher library.',
            'generator' => $reviewSet['generator'],
            'subjects' => $reviewSet['subjects'],
            'questions' => $reviewSet['questions'],
        ]);
    });

    Route::get('/exams', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $normalizeDeliveryMode) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = Exam::query()
            ->with('creator:id,name')
            ->with([
                'questionBank:id,title,subject,total_items',
                'questionBanks:id,title,subject,total_items',
                'rooms:id,name,code',
            ])
            ->withCount('rooms')
            ->where('created_by', $user->id)
            ->latest();

        $exams = $query->get()->map(function (Exam $exam) use ($normalizeDeliveryMode) {
            $exam->delivery_mode = $normalizeDeliveryMode($exam->delivery_mode);
            $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

            return $exam;
        })->values();

        return response()->json([
            'exams' => $exams,
        ]);
    });

    Route::post('/exams', function (Request $request) use (
        $isStaffMasterExaminer,
        $ensureActive,
        $recordAudit,
        $resolveExamQuestionBankIds,
        $findAccessibleQuestionBanks,
        $normalizeDeliveryMode
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'question_bank_ids' => ['nullable', 'array'],
            'question_bank_ids.*' => ['integer', 'exists:question_banks,id'],
            'question_bank_id' => ['nullable', 'integer', 'exists:question_banks,id'],
            'total_items' => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'scheduled_at' => ['nullable', 'date'],
            'schedule_start_at' => ['nullable', 'date'],
            'schedule_end_at' => ['nullable', 'date'],
            'one_take_only' => ['nullable', 'boolean'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'room_ids' => ['nullable', 'array'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
        ]);

        $deliveryMode = Exam::DELIVERY_MODE_OPEN_NAVIGATION;
        $scheduleStartAt = $validated['schedule_start_at'] ?? $validated['scheduled_at'] ?? null;
        $scheduleEndAt = $validated['schedule_end_at'] ?? null;

        if (!is_null($scheduleStartAt) && !is_null($scheduleEndAt) && strtotime((string) $scheduleEndAt) < strtotime((string) $scheduleStartAt)) {
            return response()->json([
                'message' => 'Schedule end must be after or equal to schedule start.',
            ], 422);
        }

        $selectedQuestionBankIds = $resolveExamQuestionBankIds($validated);
        $selectedQuestionBanks = $findAccessibleQuestionBanks($user, $selectedQuestionBankIds, false);

        if (count($selectedQuestionBankIds) !== $selectedQuestionBanks->count()) {
            return response()->json([
                'message' => 'One or more selected question sets are not accessible.',
            ], 422);
        }

        if ($selectedQuestionBanks->isNotEmpty() && $selectedQuestionBanks->sum('questions_count') < (int) $validated['total_items']) {
            return response()->json([
                'message' => 'Selected question sets do not have enough questions for the item count.',
            ], 422);
        }

        $exam = Exam::create([
            'title' => $validated['title'],
            'subject' => Exam::summarizeQuestionBankSubject($selectedQuestionBanks),
            'description' => $validated['description'] ?? null,
            'question_bank_id' => $selectedQuestionBankIds[0] ?? null,
            'total_items' => $validated['total_items'],
            'duration_minutes' => $validated['duration_minutes'],
            'scheduled_at' => $scheduleStartAt,
            'schedule_start_at' => $scheduleStartAt,
            'schedule_end_at' => $scheduleEndAt,
            'delivery_mode' => $deliveryMode,
            'one_take_only' => (bool) ($validated['one_take_only'] ?? false),
            'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
            'created_by' => $user->id,
        ]);

        $exam->syncQuestionBanks($selectedQuestionBankIds);

        $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

        if ($roomIds->isNotEmpty()) {
            $allowedCount = Room::query()
                ->whereIn('id', $roomIds)
                ->where('created_by', $user->id)
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

        $exam->load([
            'creator:id,name',
            'questionBank:id,title,subject,total_items',
            'questionBanks:id,title,subject,total_items',
            'rooms:id,name,code',
        ])->loadCount('rooms');
        $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

        $recordAudit(
            $user,
            'exam.create',
            'exam',
            $exam->id,
            'Exam created',
            [
                'title' => $exam->title,
                'scheduled_at' => $exam->scheduled_at,
                'schedule_start_at' => $exam->schedule_start_at,
                'schedule_end_at' => $exam->schedule_end_at,
                'delivery_mode' => $normalizeDeliveryMode($exam->delivery_mode),
                'question_bank_id' => $exam->question_bank_id,
                'question_bank_ids' => $exam->question_bank_ids,
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
        $isStaffMasterExaminer,
        $ensureActive,
        $recordAudit,
        $resolveExamQuestionBankIds,
        $findAccessibleQuestionBanks,
        $normalizeDeliveryMode
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $exam->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'question_bank_ids' => ['sometimes', 'array'],
            'question_bank_ids.*' => ['integer', 'exists:question_banks,id'],
            'question_bank_id' => ['sometimes', 'nullable', 'integer', 'exists:question_banks,id'],
            'total_items' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:600'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'schedule_start_at' => ['sometimes', 'nullable', 'date'],
            'schedule_end_at' => ['sometimes', 'nullable', 'date'],
            'one_take_only' => ['sometimes', 'boolean'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'room_ids' => ['sometimes', 'array'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
        ]);

        $currentScheduleStart = $exam->schedule_start_at ?? $exam->scheduled_at;
        $resolvedScheduleStart = array_key_exists('schedule_start_at', $validated)
            ? $validated['schedule_start_at']
            : (array_key_exists('scheduled_at', $validated) ? $validated['scheduled_at'] : $currentScheduleStart);
        $resolvedScheduleEnd = array_key_exists('schedule_end_at', $validated)
            ? $validated['schedule_end_at']
            : $exam->schedule_end_at;

        if (!is_null($resolvedScheduleStart) && !is_null($resolvedScheduleEnd) && strtotime((string) $resolvedScheduleEnd) < strtotime((string) $resolvedScheduleStart)) {
            return response()->json([
                'message' => 'Schedule end must be after or equal to schedule start.',
            ], 422);
        }

        $resolvedQuestionBankIds = $resolveExamQuestionBankIds($validated, $exam);
        $resolvedTotalItems = array_key_exists('total_items', $validated)
            ? (int) $validated['total_items']
            : (int) $exam->total_items;

        $resolvedQuestionBanks = $findAccessibleQuestionBanks($user, $resolvedQuestionBankIds, false);

        if (count($resolvedQuestionBankIds) !== $resolvedQuestionBanks->count()) {
            return response()->json([
                'message' => 'One or more selected question sets are not accessible.',
            ], 422);
        }

        if ($resolvedQuestionBanks->isNotEmpty() && $resolvedQuestionBanks->sum('questions_count') < $resolvedTotalItems) {
            return response()->json([
                'message' => 'Selected question sets do not have enough questions for the item count.',
            ], 422);
        }

        $exam->fill(collect($validated)->except([
            'scheduled_at',
            'schedule_start_at',
            'schedule_end_at',
            'question_bank_ids',
        ])->all());
        $exam->subject = Exam::summarizeQuestionBankSubject($resolvedQuestionBanks);
        $exam->question_bank_id = $resolvedQuestionBankIds[0] ?? null;
        $exam->scheduled_at = $resolvedScheduleStart;
        $exam->schedule_start_at = $resolvedScheduleStart;
        $exam->schedule_end_at = $resolvedScheduleEnd;
        $exam->delivery_mode = Exam::DELIVERY_MODE_OPEN_NAVIGATION;

        $exam->save();
        $exam->syncQuestionBanks($resolvedQuestionBankIds);

        if (array_key_exists('room_ids', $validated)) {
            $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

            if ($roomIds->isNotEmpty()) {
                $allowedCount = Room::query()
                    ->whereIn('id', $roomIds)
                    ->where('created_by', $user->id)
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

        $exam->load([
            'creator:id,name',
            'questionBank:id,title,subject,total_items',
            'questionBanks:id,title,subject,total_items',
            'rooms:id,name,code',
        ])->loadCount('rooms');
        $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

        $recordAudit(
            $user,
            'exam.update',
            'exam',
            $exam->id,
            'Exam updated',
            [
                'title' => $exam->title,
                'scheduled_at' => $exam->scheduled_at,
                'schedule_start_at' => $exam->schedule_start_at,
                'schedule_end_at' => $exam->schedule_end_at,
                'delivery_mode' => $normalizeDeliveryMode($exam->delivery_mode),
                'question_bank_id' => $exam->question_bank_id,
                'question_bank_ids' => $exam->question_bank_ids,
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

    Route::delete('/exams/{exam}', function (Request $request, Exam $exam) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $exam->created_by !== (int) $user->id) {
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
        $isStaffMasterExaminer,
        $ensureActive,
        $normalizeDeliveryMode
    ) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $exam->created_by !== (int) $user->id) {
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

        if ((int) $room->created_by !== (int) $user->id) {
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
                'schedule_start_at' => $exam->schedule_start_at,
                'schedule_end_at' => $exam->schedule_end_at,
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
            'rows' => $rows,
            'item_summary' => $itemSummary,
            'generated_at' => now(),
        ]);
    });

    Route::post('/student/exams/{exam}/start', function (
        Request $request,
        Exam $exam
    ) use (
        $canManageRooms,
        $ensureActive,
        $recordAudit,
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

        $scheduleStartAt = $exam->schedule_start_at ?? $exam->scheduled_at;
        $scheduleEndAt = $exam->schedule_end_at;

        if ($scheduleStartAt && now()->lt($scheduleStartAt)) {
            return response()->json([
                'message' => 'This exam is not yet available based on schedule.',
                'available_at' => $scheduleStartAt,
            ], 422);
        }

        if ($scheduleEndAt && now()->gt($scheduleEndAt)) {
            return response()->json([
                'message' => 'This exam is no longer available because the schedule window has ended.',
                'available_until' => $scheduleEndAt,
            ], 422);
        }

        $questionBankIds = $exam->resolvedQuestionBankIds();

        if ($questionBankIds === []) {
            return response()->json([
                'message' => 'This exam has no question set configured yet.',
            ], 422);
        }

        $questionBankOrder = array_flip($questionBankIds);
        $questionBanks = QuestionBank::query()
            ->withCount('questions')
            ->whereIn('id', $questionBankIds)
            ->get()
            ->sortBy(fn (QuestionBank $bank) => $questionBankOrder[(int) $bank->id] ?? PHP_INT_MAX)
            ->values();

        if ($questionBanks->count() !== count($questionBankIds)) {
            return response()->json([
                'message' => 'One or more linked question sets could not be found.',
            ], 422);
        }

        $availableQuestionCount = (int) $questionBanks->sum('questions_count');
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

        $submittedAttemptCount = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->count();

        $maxAllowedAttempts = (bool) $exam->one_take_only ? 1 : 2;

        if ($submittedAttemptCount >= $maxAllowedAttempts) {
            return response()->json([
                'message' => (bool) $exam->one_take_only
                    ? 'This exam allows one attempt only and your attempt is already submitted.'
                    : 'Retake limit reached. You can only take this exam twice (1 initial attempt + 1 retake).',
            ], 422);
        }

        $questionQuery = DB::table('question_bank_questions')
            ->whereIn('question_bank_id', $questionBankIds);

        if ((bool) $exam->shuffle_questions) {
            $questionQuery->inRandomOrder();
        } else {
            $bankOrderSql = collect($questionBankIds)
                ->values()
                ->map(fn ($bankId, $index) => 'WHEN ' . (int) $bankId . ' THEN ' . $index)
                ->implode(' ');

            $questionQuery
                ->orderByRaw('CASE question_bank_id ' . $bankOrderSql . ' ELSE ' . count($questionBankIds) . ' END')
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

        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:question_bank_questions,id'],
            'selected_option_id' => ['nullable', 'integer', 'exists:question_bank_options,id'],
            'answer_text' => ['nullable', 'string'],
        ]);

        $questionId = (int) $validated['question_id'];

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

    Route::get('/reports/overview', function (Request $request) use ($isStaffMasterExaminer, $ensureActive) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $user = $request->user();

        if (!$isStaffMasterExaminer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

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

        return response()->json([
            'metrics' => $metrics,
            'recent_activity' => $recentActivity,
        ]);
    });

    Route::get('/reports/exams/{exam}/rooms/{room}/complete-results.csv', function (
        Request $request,
        Exam $exam,
        Room $room
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $session = $reportExportService->buildSessionLatestResults($exam, $room);
        $table = $reportExportService->buildCompleteResultsTable($session);

        $fileLabel = trim((string) Str::slug(($exam->title ?? 'exam') . '-' . ($room->code ?? 'room')));
        $filename = ($fileLabel !== '' ? $fileLabel : 'session') . '-complete-results-' . now()->format('Ymd_His') . '.csv';

        $recordAudit(
            $user,
            'report.export.complete_results_csv',
            'exam',
            $exam->id,
            'Exported complete results CSV',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'students_total' => (int) ($session['summary']['students_total'] ?? 0),
            ],
            $request,
        );

        return response()->streamDownload(function () use ($table): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            fputcsv($stream, $table['headers']);

            foreach ($table['rows'] as $row) {
                fputcsv($stream, $row);
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    });

    Route::get('/reports/exams/{exam}/rooms/{room}/complete-results.xlsx', function (
        Request $request,
        Exam $exam,
        Room $room
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $session = $reportExportService->buildSessionLatestResults($exam, $room);
        $table = $reportExportService->buildCompleteResultsTable($session);

        $spreadsheet = new Spreadsheet();
        $resultsSheet = $spreadsheet->getActiveSheet();
        $resultsSheet->setTitle('Results');
        $resultsSheet->fromArray($table['headers'], null, 'A1');

        if (!empty($table['rows'])) {
            $resultsSheet->fromArray($table['rows'], null, 'A2');
        }

        $highestResultsColumn = $resultsSheet->getHighestColumn();
        $resultsSheet->getStyle('A1:' . $highestResultsColumn . '1')->getFont()->setBold(true);

        for ($columnIndex = 1; $columnIndex <= count($table['headers']); $columnIndex++) {
            $columnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $resultsSheet->getColumnDimension($columnName)->setAutoSize(true);
        }

        $analysisSheet = $spreadsheet->createSheet();
        $analysisSheet->setTitle('Item Analysis');

        $analysisHeaders = ['Item', 'Question', 'Started', 'Answered', 'Correct', '% Answered', '% Correct'];
        $analysisSheet->fromArray($analysisHeaders, null, 'A1');

        $analysisRows = collect($session['item_summary'] ?? [])->map(function (array $item): array {
            return [
                (int) ($item['item_number'] ?? 0),
                (string) ($item['question_text'] ?? ''),
                (int) ($item['started_count'] ?? 0),
                (int) ($item['answered_count'] ?? 0),
                (int) ($item['correct_count'] ?? 0),
                is_null($item['answered_percent'] ?? null) ? '' : (float) $item['answered_percent'],
                is_null($item['correct_percent'] ?? null) ? '' : (float) $item['correct_percent'],
            ];
        })->values()->all();

        if (!empty($analysisRows)) {
            $analysisSheet->fromArray($analysisRows, null, 'A2');
        }

        $highestAnalysisColumn = $analysisSheet->getHighestColumn();
        $analysisSheet->getStyle('A1:' . $highestAnalysisColumn . '1')->getFont()->setBold(true);

        for ($columnIndex = 1; $columnIndex <= count($analysisHeaders); $columnIndex++) {
            $columnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $analysisSheet->getColumnDimension($columnName)->setAutoSize(true);
        }

        $fileLabel = trim((string) Str::slug(($exam->title ?? 'exam') . '-' . ($room->code ?? 'room')));
        $filename = ($fileLabel !== '' ? $fileLabel : 'session') . '-complete-results-' . now()->format('Ymd_His') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), 'blis_xlsx_');

        if ($tempFile === false) {
            return response()->json(['message' => 'Unable to generate export file.'], 500);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $recordAudit(
            $user,
            'report.export.complete_results_xlsx',
            'exam',
            $exam->id,
            'Exported complete results XLSX',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'students_total' => (int) ($session['summary']['students_total'] ?? 0),
            ],
            $request,
        );

        return response()->download(
            $tempFile,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    });

    Route::get('/reports/exams/{exam}/rooms/{room}/summary.pdf', function (
        Request $request,
        Exam $exam,
        Room $room
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $session = $reportExportService->buildSessionLatestResults($exam, $room);

        $fileLabel = trim((string) Str::slug(($exam->title ?? 'exam') . '-' . ($room->code ?? 'room')));
        $filename = ($fileLabel !== '' ? $fileLabel : 'session') . '-results-summary-' . now()->format('Ymd_His') . '.pdf';

        $recordAudit(
            $user,
            'report.export.summary_pdf',
            'exam',
            $exam->id,
            'Exported results summary PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
            ],
            $request,
        );

        return Pdf::loadView('reports.summary_pdf', [
            'session' => $session,
        ])->setPaper('a4', 'portrait')->download($filename);
    });

    Route::get('/reports/exams/{exam}/rooms/{room}/answer-key.pdf', function (
        Request $request,
        Exam $exam,
        Room $room
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $answerKey = $reportExportService->buildAnswerKey($exam);

        $fileLabel = trim((string) Str::slug(($exam->title ?? 'exam') . '-' . ($room->code ?? 'room')));
        $filename = ($fileLabel !== '' ? $fileLabel : 'session') . '-answer-key-' . now()->format('Ymd_His') . '.pdf';

        $recordAudit(
            $user,
            'report.export.answer_key_pdf',
            'exam',
            $exam->id,
            'Exported answer key PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
            ],
            $request,
        );

        return Pdf::loadView('reports.answer_key_pdf', [
            'exam' => [
                'id' => (int) $exam->id,
                'title' => $exam->title,
                'subject' => $exam->subject,
            ],
            'room' => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ],
            'answerKey' => $answerKey,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait')->download($filename);
    });

    Route::get('/reports/exams/{exam}/rooms/{room}/students/{student}/student-report.pdf', function (
        Request $request,
        Exam $exam,
        Room $room,
        User $student
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        if ($student->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $isMember = $room->members()->where('users.id', (int) $student->id)->exists();
        if (!$isMember) {
            return response()->json(['message' => 'Student is not enrolled in the selected room.'], 422);
        }

        $attempt = $reportExportService->findLatestAttemptForStudent($exam, $room, (int) $student->id);
        if (!$attempt) {
            return response()->json(['message' => 'No attempt found for this student in the selected session.'], 404);
        }

        $report = $reportExportService->buildAttemptReportData($attempt);

        $studentLabel = trim((string) ($report['student']['student_id'] ?? 'student'));
        $studentSlug = trim((string) Str::slug((string) ($report['student']['name'] ?? 'student')));
        $baseName = trim($studentLabel . '-' . ($studentSlug !== '' ? $studentSlug : 'student'));
        $filename = ($baseName !== '' ? $baseName : 'student-report')
            . '-attempt-' . (int) $attempt->id
            . '.pdf';

        $recordAudit(
            $user,
            'report.export.individual_student_pdf',
            'exam_attempt',
            $attempt->id,
            'Exported individual student report PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'student_id' => (int) $student->id,
            ],
            $request,
        );

        return Pdf::loadView('reports.student_report_pdf', [
            'report' => $report,
        ])->setPaper('a4', 'portrait')->download($filename);
    });

    Route::post('/reports/exams/{exam}/rooms/{room}/students/{student}/student-report.email', function (
        Request $request,
        Exam $exam,
        Room $room,
        User $student
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $validated = $request->validate([
            'verified_only' => ['sometimes', 'boolean'],
        ]);
        $verifiedOnly = (bool) ($validated['verified_only'] ?? false);

        if ($student->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $isMember = $room->members()->where('users.id', (int) $student->id)->exists();
        if (!$isMember) {
            return response()->json(['message' => 'Student is not enrolled in the selected room.'], 422);
        }

        $recipientEmail = trim((string) ($student->email ?? ''));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Student does not have a valid email address.'], 422);
        }

        if ($verifiedOnly && is_null($student->email_verified_at)) {
            return response()->json(['message' => 'Student email is not verified.'], 422);
        }

        $attempt = $reportExportService->findLatestAttemptForStudent($exam, $room, (int) $student->id);
        if (!$attempt) {
            return response()->json(['message' => 'No attempt found for this student in the selected session.'], 404);
        }

        $report = $reportExportService->buildAttemptReportData($attempt);

        $studentLabel = trim((string) ($report['student']['student_id'] ?? 'student'));
        $studentSlug = trim((string) Str::slug((string) ($report['student']['name'] ?? 'student')));
        $baseName = trim($studentLabel . '-' . ($studentSlug !== '' ? $studentSlug : 'student'));
        $filename = ($baseName !== '' ? $baseName : 'student-report')
            . '-attempt-' . (int) $attempt->id
            . '.pdf';

        $pdfBinary = Pdf::loadView('reports.student_report_pdf', [
            'report' => $report,
        ])->setPaper('a4', 'portrait')->output();

        try {
            Mail::to($recipientEmail)->send(new StudentReportMail(
                $report,
                $user,
                $pdfBinary,
                $filename
            ));
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to send report email.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        $recordAudit(
            $user,
            'report.email.individual_student_pdf',
            'exam_attempt',
            $attempt->id,
            'Emailed individual student report PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'student_id' => (int) $student->id,
                'recipient_email' => $recipientEmail,
                'verified_only' => $verifiedOnly,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Student report emailed successfully.',
            'student' => [
                'id' => (int) $student->id,
                'name' => $student->name,
                'student_id' => $student->student_id,
                'email' => $recipientEmail,
            ],
            'attempt_id' => (int) $attempt->id,
        ]);
    });

    Route::post('/reports/exams/{exam}/rooms/{room}/student-reports.email', function (
        Request $request,
        Exam $exam,
        Room $room
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $validated = $request->validate([
            'verified_only' => ['sometimes', 'boolean'],
        ]);
        $verifiedOnly = (bool) ($validated['verified_only'] ?? false);

        $students = $room->members()
            ->select('users.id', 'users.name', 'users.email', 'users.student_id', 'users.email_verified_at', 'users.role')
            ->where('users.role', User::ROLE_STUDENT)
            ->orderBy('users.name')
            ->get();

        $latestAttempts = $reportExportService
            ->getLatestSessionAttemptsByStudent($exam, $room)
            ->keyBy('user_id');

        $sent = [];
        $issues = [];

        foreach ($students as $student) {
            $recipientEmail = trim((string) ($student->email ?? ''));

            if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'invalid_email',
                ];
                continue;
            }

            if ($verifiedOnly && is_null($student->email_verified_at)) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'email_not_verified',
                ];
                continue;
            }

            /** @var ExamAttempt|null $attempt */
            $attempt = $latestAttempts->get((int) $student->id);
            if (!$attempt) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'no_attempt',
                ];
                continue;
            }

            $report = $reportExportService->buildAttemptReportData($attempt);

            $studentLabel = trim((string) ($report['student']['student_id'] ?? 'student'));
            $studentSlug = trim((string) Str::slug((string) ($report['student']['name'] ?? 'student')));
            $baseName = trim($studentLabel . '-' . ($studentSlug !== '' ? $studentSlug : 'student'));
            $filename = ($baseName !== '' ? $baseName : 'student-report')
                . '-attempt-' . (int) $attempt->id
                . '.pdf';

            $pdfBinary = Pdf::loadView('reports.student_report_pdf', [
                'report' => $report,
            ])->setPaper('a4', 'portrait')->output();

            try {
                Mail::to($recipientEmail)->send(new StudentReportMail(
                    $report,
                    $user,
                    $pdfBinary,
                    $filename
                ));
            } catch (\Throwable $exception) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'mail_send_failed',
                    'error' => $exception->getMessage(),
                ];
                continue;
            }

            $sent[] = [
                'student_id' => (int) $student->id,
                'student_name' => $student->name,
                'student_number' => $student->student_id,
                'email' => $recipientEmail,
                'attempt_id' => (int) $attempt->id,
            ];
        }

        $summary = [
            'students_total' => (int) $students->count(),
            'sent_count' => (int) count($sent),
            'issue_count' => (int) count($issues),
            'verified_only' => $verifiedOnly,
        ];

        $recordAudit(
            $user,
            'report.email.student_reports_bulk',
            'exam',
            $exam->id,
            'Emailed bulk student report PDFs',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'students_total' => (int) $students->count(),
                'sent_count' => (int) count($sent),
                'issue_count' => (int) count($issues),
                'verified_only' => $verifiedOnly,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Bulk student report email process completed.',
            'summary' => $summary,
            'sent' => $sent,
            'issues' => $issues,
        ]);
    });

    Route::get('/reports/exams/{exam}/rooms/{room}/student-reports.zip', function (
        Request $request,
        Exam $exam,
        Room $room
    ) use ($ensureTeacherReportAccess, $reportExportService, $recordAudit) {
        $access = $ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $attempts = $reportExportService->getSessionAttempts($exam, $room);

        if ($attempts->isEmpty()) {
            return response()->json([
                'message' => 'No student attempts are available for this session.',
            ], 422);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'blis_zip_');
        if ($zipPath === false) {
            return response()->json(['message' => 'Unable to create ZIP export.'], 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);

            return response()->json(['message' => 'Unable to open ZIP export stream.'], 500);
        }

        foreach ($attempts as $attempt) {
            $report = $reportExportService->buildAttemptReportData($attempt);

            $pdfBinary = Pdf::loadView('reports.student_report_pdf', [
                'report' => $report,
            ])->setPaper('a4', 'portrait')->output();

            $studentLabel = trim((string) ($report['student']['student_id'] ?? 'student'));
            $studentSlug = trim((string) Str::slug((string) ($report['student']['name'] ?? 'student')));
            $baseName = trim($studentLabel . '-' . ($studentSlug !== '' ? $studentSlug : 'student'));
            $entryName = ($baseName !== '' ? $baseName : 'student')
                . '-attempt-' . (int) $attempt->id
                . '.pdf';

            $zip->addFromString($entryName, $pdfBinary);
        }

        $zip->close();

        $fileLabel = trim((string) Str::slug(($exam->title ?? 'exam') . '-' . ($room->code ?? 'room')));
        $filename = ($fileLabel !== '' ? $fileLabel : 'session') . '-student-reports-' . now()->format('Ymd_His') . '.zip';

        $recordAudit(
            $user,
            'report.export.student_reports_zip',
            'exam',
            $exam->id,
            'Exported bulk student reports ZIP',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'attempt_count' => (int) $attempts->count(),
            ],
            $request,
        );

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
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
            'terms_of_use_text' => ['sometimes', 'required', 'string', 'max:20000'],
            'privacy_policy_text' => ['sometimes', 'required', 'string', 'max:20000'],
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
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $page = (int) ($validated['page'] ?? 1);
            $perPage = (int) ($validated['per_page'] ?? 50);

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
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'users' => $users->items(),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                ],
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

        Route::post('/users/{user}/recover-account', function (Request $request, User $user) use ($ensureActive, $isAdmin, $recordAudit) {
            if ($inactiveResponse = $ensureActive($request)) {
                return $inactiveResponse;
            }

            $actor = $request->user();
            if (!$isAdmin($actor)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if ($user->role !== User::ROLE_STUDENT) {
                return response()->json([
                    'message' => 'Account recovery via student ID is only available for student accounts.',
                ], 422);
            }

            if (blank($user->student_id)) {
                return response()->json([
                    'message' => 'This student account has no student ID on file.',
                ], 422);
            }

            $validated = $request->validate([
                'student_id' => ['required', 'string', 'max:32', 'regex:/^\d{7,20}$/'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'password' => ['required', 'string', 'min:8'],
            ]);

            $enteredStudentId = trim((string) $validated['student_id']);
            $storedStudentId = trim((string) $user->student_id);

            if ($enteredStudentId !== $storedStudentId) {
                return response()->json([
                    'message' => 'Student ID verification failed.',
                ], 422);
            }

            $oldEmail = $user->email;

            $user->email = $validated['email'];
            $user->password = $validated['password'];
            $user->save();

            // Revoke all active sessions/tokens so only the recovered credentials remain valid.
            $user->tokens()->delete();

            $recordAudit(
                $actor,
                'admin.user.recover',
                'user',
                $user->id,
                'Admin recovered student account access',
                [
                    'old_email' => $oldEmail,
                    'new_email' => $user->email,
                    'student_id' => $user->student_id,
                    'tokens_revoked' => true,
                ],
                $request,
            );

            return response()->json([
                'message' => 'Student account recovered. Email and password were updated and active sessions were revoked.',
                'user' => $user->only(['id', 'name', 'email', 'student_id', 'role', 'is_active']),
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
