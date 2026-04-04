<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsService
{
    private const PASSING_SCORE = 75.0;
    private const STUDENT_HISTORY_LIMIT = 6;
    private const STUDENT_ACTIVITY_LIMIT = 8;
    private const STAFF_ACTIVITY_LIMIT = 14;
    private const STAFF_SESSION_LIMIT = 8;

    /**
     * @return array<string, mixed>
     */
    public function buildStudentOverview(User $student): array
    {
        $roomsJoined = (int) $student->rooms()->count();

        $availableExamIds = DB::table('room_user')
            ->join('exam_room', 'exam_room.room_id', '=', 'room_user.room_id')
            ->join('exams', 'exams.id', '=', 'exam_room.exam_id')
            ->where('room_user.user_id', $student->id)
            ->whereNull('exam_room.archived_at')
            ->distinct()
            ->pluck('exam_room.exam_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $attempts = ExamAttempt::query()
            ->where('user_id', $student->id)
            ->with([
                'exam:id,title,subject',
                'room:id,name,code',
            ])
            ->get()
            ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptActivityTimestamp($attempt))
            ->values();

        $submittedAttempts = $attempts
            ->filter(fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED)
            ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
            ->values();

        $scoreValues = $this->extractScores($submittedAttempts);
        $passingAttempts = $scoreValues
            ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
            ->count();
        $completedExams = $submittedAttempts
            ->pluck('exam_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->count();
        $subjects = $this->buildSubjectPerformance($submittedAttempts);
        $latestSubmittedAttempt = $submittedAttempts->first();

        $scoreHistory = $submittedAttempts
            ->take(self::STUDENT_HISTORY_LIMIT)
            ->sortBy(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
            ->values()
            ->map(fn (ExamAttempt $attempt, int $index) => [
                'attempt_id' => (int) $attempt->id,
                'label' => (string) ($attempt->exam?->title ?: ('Attempt ' . ($index + 1))),
                'short_label' => 'A' . ($index + 1),
                'subject' => $this->normalizeSubjectLabel($attempt->exam?->subject),
                'room_name' => $attempt->room?->name,
                'score_percent' => $this->scoreValue($attempt),
                'submitted_at' => $attempt->submitted_at,
            ])
            ->all();

        $recentActivity = $attempts
            ->take(self::STUDENT_ACTIVITY_LIMIT)
            ->map(fn (ExamAttempt $attempt) => [
                'id' => (int) $attempt->id,
                'title' => (string) ($attempt->exam?->title ?: 'Exam Attempt'),
                'subject' => $this->normalizeSubjectLabel($attempt->exam?->subject),
                'room_name' => $attempt->room?->name,
                'room_code' => $attempt->room?->code,
                'status' => $attempt->status,
                'score_percent' => $this->scoreValue($attempt),
                'occurred_at' => $attempt->status === ExamAttempt::STATUS_SUBMITTED
                    ? ($attempt->submitted_at ?? $attempt->started_at ?? $attempt->created_at)
                    : ($attempt->started_at ?? $attempt->created_at),
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'rooms_joined' => $roomsJoined,
                'available_exams' => (int) $availableExamIds->count(),
                'pending_exams' => max(0, (int) $availableExamIds->count() - $completedExams),
                'attempts_started' => (int) $attempts->count(),
                'attempts_submitted' => (int) $submittedAttempts->count(),
                'completed_exams' => $completedExams,
                'in_progress_attempts' => (int) $attempts
                    ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
                    ->count(),
                'passing_attempts' => (int) $passingAttempts,
                'failing_attempts' => max(0, (int) $submittedAttempts->count() - (int) $passingAttempts),
                'passing_threshold' => self::PASSING_SCORE,
                'average_score_percent' => $scoreValues->isNotEmpty()
                    ? round((float) $scoreValues->avg(), 2)
                    : null,
                'pass_rate_percent' => $submittedAttempts->isNotEmpty()
                    ? round(($passingAttempts / $submittedAttempts->count()) * 100, 2)
                    : null,
                'best_score_percent' => $scoreValues->isNotEmpty()
                    ? round((float) $scoreValues->max(), 2)
                    : null,
                'latest_score_percent' => $latestSubmittedAttempt
                    ? $this->scoreValue($latestSubmittedAttempt)
                    : null,
            ],
            'subjects' => $subjects,
            'focus_subjects' => collect($subjects)
                ->filter(fn (array $subject) => (float) ($subject['score'] ?? 0) < self::PASSING_SCORE)
                ->values()
                ->all(),
            'score_history' => $scoreHistory,
            'recent_activity' => $recentActivity,
            'generated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStaffReportOverview(User $staff): array
    {
        $managedRooms = Room::query()
            ->where('created_by', $staff->id)
            ->get(['id', 'name', 'code']);
        $managedExams = Exam::query()
            ->where('created_by', $staff->id)
            ->get(['id', 'title', 'subject']);

        $roomIds = $managedRooms->pluck('id')->map(fn ($id) => (int) $id)->values();
        $examIds = $managedExams->pluck('id')->map(fn ($id) => (int) $id)->values();

        $studentsEnrolled = DB::table('room_user')
            ->join('rooms', 'rooms.id', '=', 'room_user.room_id')
            ->join('users', 'users.id', '=', 'room_user.user_id')
            ->where('rooms.created_by', $staff->id)
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at')
            ->distinct('room_user.user_id')
            ->count('room_user.user_id');

        $studentCountsByRoomId = $roomIds->isNotEmpty()
            ? DB::table('room_user')
                ->join('users', 'users.id', '=', 'room_user.user_id')
                ->whereIn('room_user.room_id', $roomIds->all())
                ->where('users.role', User::ROLE_STUDENT)
                ->whereNull('users.archived_at')
                ->groupBy('room_user.room_id')
                ->selectRaw('room_user.room_id, COUNT(DISTINCT room_user.user_id) as students_count')
                ->pluck('students_count', 'room_user.room_id')
            : collect();

        $currentStudentIds = $roomIds->isNotEmpty()
            ? DB::table('room_user')
                ->join('users', 'users.id', '=', 'room_user.user_id')
                ->whereIn('room_user.room_id', $roomIds->all())
                ->where('users.role', User::ROLE_STUDENT)
                ->whereNull('users.archived_at')
                ->distinct()
                ->pluck('room_user.user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
            : collect();

        $sessionRows = ($examIds->isNotEmpty() && $roomIds->isNotEmpty())
            ? DB::table('exam_room')
                ->join('exams', 'exams.id', '=', 'exam_room.exam_id')
                ->join('rooms', 'rooms.id', '=', 'exam_room.room_id')
                ->where('exams.created_by', $staff->id)
                ->where('rooms.created_by', $staff->id)
                ->whereNull('exam_room.archived_at')
                ->select(
                    'exam_room.exam_id',
                    'exam_room.room_id',
                    'exam_room.created_at as assigned_at',
                    'exams.title as exam_title',
                    'exams.subject as exam_subject',
                    'rooms.name as room_name',
                    'rooms.code as room_code',
                )
                ->orderByDesc('exam_room.created_at')
                ->get()
            : collect();

        $attemptIdsQuery = DB::table('exam_attempts')
            ->whereIn('exam_id', $examIds->all())
            ->whereIn('room_id', $roomIds->all())
            ->whereIn('user_id', $currentStudentIds->all())
            ->groupBy('exam_id', 'room_id', 'user_id')
            ->selectRaw('MAX(id) as id');

        $attempts = ($examIds->isNotEmpty() && $roomIds->isNotEmpty() && $currentStudentIds->isNotEmpty())
            ? ExamAttempt::query()
                ->joinSub($attemptIdsQuery, 'latest_ids', function ($join) {
                    $join->on('exam_attempts.id', '=', 'latest_ids.id');
                })
                ->with([
                    'exam:id,title,subject',
                    'room:id,name,code',
                ])
                ->get()
            : collect();

        $activeSessionKeys = collect($sessionRows)
            ->map(fn ($session) => $this->sessionKey((int) $session->exam_id, (int) $session->room_id))
            ->values();

        $latestAttemptsBySession = collect($attempts)
            ->groupBy(fn (ExamAttempt $attempt) => $this->sessionKey((int) $attempt->exam_id, (int) $attempt->room_id))
            ->map(function (Collection $sessionAttempts): Collection {
                return $sessionAttempts
                    ->groupBy('user_id')
                    ->map(function (Collection $studentAttempts): ?ExamAttempt {
                        return $studentAttempts
                            ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptActivityTimestamp($attempt))
                            ->first();
                    })
                    ->filter()
                    ->values();
            });

        $latestSessionAttempts = $latestAttemptsBySession
            ->only($activeSessionKeys->all())
            ->flatten(1)
            ->values();
        $submittedLatestAttempts = $latestSessionAttempts
            ->filter(fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED)
            ->values();

        $submittedScoreValues = $this->extractScores($submittedLatestAttempts);
        $passingLatestAttempts = $submittedScoreValues
            ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
            ->count();
        $sessionEnrollments = (int) $sessionRows
            ->sum(fn ($session) => (int) ($studentCountsByRoomId[(int) $session->room_id] ?? 0));

        $sessionPerformance = collect($sessionRows)
            ->map(function ($session) use ($latestAttemptsBySession, $studentCountsByRoomId): array {
                $sessionAttempts = $latestAttemptsBySession->get(
                    $this->sessionKey((int) $session->exam_id, (int) $session->room_id),
                    collect(),
                );
                $submittedAttempts = $sessionAttempts
                    ->filter(fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED)
                    ->values();
                $submittedScores = $this->extractScores($submittedAttempts);
                $studentsTotal = (int) ($studentCountsByRoomId[(int) $session->room_id] ?? 0);
                $studentsStarted = (int) $sessionAttempts->count();
                $studentsSubmitted = (int) $submittedAttempts->count();
                $passingCount = $submittedScores
                    ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
                    ->count();
                $latestSubmission = $submittedAttempts
                    ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
                    ->first();

                return [
                    'exam_id' => (int) $session->exam_id,
                    'room_id' => (int) $session->room_id,
                    'exam_title' => (string) $session->exam_title,
                    'subject' => $this->normalizeSubjectLabel($session->exam_subject),
                    'room_name' => (string) $session->room_name,
                    'room_code' => $session->room_code,
                    'students_total' => $studentsTotal,
                    'students_started' => $studentsStarted,
                    'students_submitted' => $studentsSubmitted,
                    'start_rate_percent' => $studentsTotal > 0
                        ? round(($studentsStarted / $studentsTotal) * 100, 2)
                        : null,
                    'completion_rate_percent' => $studentsTotal > 0
                        ? round(($studentsSubmitted / $studentsTotal) * 100, 2)
                        : null,
                    'average_score_percent' => $submittedScores->isNotEmpty()
                        ? round((float) $submittedScores->avg(), 2)
                        : null,
                    'pass_rate_percent' => $submittedAttempts->isNotEmpty()
                        ? round(($passingCount / $submittedAttempts->count()) * 100, 2)
                        : null,
                    'latest_submission_at' => $latestSubmission?->submitted_at,
                    'assigned_at' => $session->assigned_at,
                ];
            })
            ->sortByDesc(fn (array $session) => $this->dateValueTimestamp($session['latest_submission_at'] ?? $session['assigned_at'] ?? null))
            ->take(self::STAFF_SESSION_LIMIT)
            ->values()
            ->all();

        return [
            'metrics' => [
                'managed_rooms' => (int) $managedRooms->count(),
                'managed_exams' => (int) $managedExams->count(),
                'students_enrolled' => (int) $studentsEnrolled,
                'exam_assignments' => (int) collect($sessionRows)->count(),
                'session_enrollments' => $sessionEnrollments,
                'attempts_started' => (int) $latestSessionAttempts->count(),
                'attempts_submitted' => (int) $submittedLatestAttempts->count(),
                'completion_rate_percent' => $sessionEnrollments > 0
                    ? round(($submittedLatestAttempts->count() / $sessionEnrollments) * 100, 2)
                    : null,
                'average_score_percent' => $submittedScoreValues->isNotEmpty()
                    ? round((float) $submittedScoreValues->avg(), 2)
                    : null,
                'pass_rate_percent' => $submittedLatestAttempts->isNotEmpty()
                    ? round(($passingLatestAttempts / $submittedLatestAttempts->count()) * 100, 2)
                    : null,
            ],
            'subject_performance' => $this->buildSubjectPerformance($submittedLatestAttempts),
            'session_performance' => $sessionPerformance,
            'recent_activity' => AuditLog::query()
                ->with('actor:id,name,role')
                ->where('actor_id', $staff->id)
                ->latest()
                ->limit(self::STAFF_ACTIVITY_LIMIT)
                ->get(),
            'generated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStudentDirectory(): array
    {
        $students = User::query()
            ->select('id', 'name', 'email', 'student_id', 'role', 'year_level', 'is_active', 'archived_at')
            ->where('role', User::ROLE_STUDENT)
            ->with([
                'rooms:id,name,code',
            ])
            ->orderByRaw('CASE WHEN year_level IS NULL THEN 5 ELSE year_level END')
            ->orderBy('name')
            ->get();

        $studentIds = $students
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $attemptIdsQuery = DB::table('exam_attempts')
            ->whereIn('user_id', $studentIds->all())
            ->groupBy('user_id', 'exam_id', 'room_id')
            ->selectRaw('MAX(id) as id');

        $attempts = $studentIds->isNotEmpty()
            ? ExamAttempt::query()
                ->joinSub($attemptIdsQuery, 'latest_ids', function ($join) {
                    $join->on('exam_attempts.id', '=', 'latest_ids.id');
                })
                ->with([
                    'exam:id,title,subject',
                    'room:id,name,code',
                ])
                ->get()
            : collect();

        $latestAttemptsByStudent = collect($attempts)
            ->groupBy('user_id')
            ->map(function (Collection $studentAttempts): Collection {
                return $studentAttempts
                    ->groupBy(fn (ExamAttempt $attempt) => $this->sessionKey((int) $attempt->exam_id, (int) $attempt->room_id))
                    ->map(fn (Collection $sessionAttempts) => $sessionAttempts
                        ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptActivityTimestamp($attempt))
                        ->first()
                    )
                    ->filter()
                    ->values();
            });

        $studentRecords = $students
            ->map(function (User $student) use ($latestAttemptsByStudent): array {
                $latestSessionAttempts = $latestAttemptsByStudent->get((int) $student->id, collect());
                $submittedAttempts = $latestSessionAttempts
                    ->filter(fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED)
                    ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
                    ->values();
                $scores = $this->extractScores($submittedAttempts);
                $passingAttempts = $scores
                    ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
                    ->count();
                $latestActivity = $latestSessionAttempts
                    ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptActivityTimestamp($attempt))
                    ->first();
                $latestSubmittedAttempt = $submittedAttempts->first();
                $strongestSubject = collect($this->buildSubjectPerformance($submittedAttempts))->first();
                $rooms = $student->rooms
                    ->sortBy('name')
                    ->values();

                return [
                    'id' => (int) $student->id,
                    'name' => (string) $student->name,
                    'email' => (string) $student->email,
                    'student_id' => $student->student_id,
                    'year_level' => $student->year_level,
                    'is_active' => (bool) $student->is_active,
                    'archived_at' => $student->archived_at,
                    'room_count' => (int) $rooms->count(),
                    'room_names' => $rooms
                        ->pluck('name')
                        ->values()
                        ->all(),
                    'attempts_started' => (int) $latestSessionAttempts->count(),
                    'attempts_submitted' => (int) $submittedAttempts->count(),
                    'average_score_percent' => $scores->isNotEmpty()
                        ? round((float) $scores->avg(), 2)
                        : null,
                    'pass_rate_percent' => $submittedAttempts->isNotEmpty()
                        ? round(($passingAttempts / $submittedAttempts->count()) * 100, 2)
                        : null,
                    'latest_score_percent' => $latestSubmittedAttempt
                        ? $this->scoreValue($latestSubmittedAttempt)
                        : null,
                    'latest_exam_title' => $latestSubmittedAttempt?->exam?->title,
                    'latest_room_name' => $latestSubmittedAttempt?->room?->name,
                    'last_activity_at' => $latestActivity
                        ? ($latestActivity->submitted_at ?? $latestActivity->started_at ?? $latestActivity->created_at)
                        : null,
                    'strongest_subject' => is_array($strongestSubject)
                        ? ($strongestSubject['label'] ?? null)
                        : null,
                ];
            })
            ->values();

        $currentStudents = $studentRecords
            ->filter(fn (array $student) => empty($student['archived_at']))
            ->values();
        $archivedStudents = $studentRecords
            ->filter(fn (array $student) => !empty($student['archived_at']))
            ->values();
        $currentScores = $currentStudents
            ->pluck('average_score_percent')
            ->filter(fn ($score) => !is_null($score))
            ->map(fn ($score) => (float) $score)
            ->values();
        $currentPassRates = $currentStudents
            ->pluck('pass_rate_percent')
            ->filter(fn ($score) => !is_null($score))
            ->map(fn ($score) => (float) $score)
            ->values();

        return [
            'summary' => [
                'current_students' => (int) $currentStudents->count(),
                'archived_students' => (int) $archivedStudents->count(),
                'students_with_results' => (int) $currentStudents
                    ->filter(fn (array $student) => (int) ($student['attempts_submitted'] ?? 0) > 0)
                    ->count(),
                'average_score_percent' => $currentScores->isNotEmpty()
                    ? round((float) $currentScores->avg(), 2)
                    : null,
                'average_pass_rate_percent' => $currentPassRates->isNotEmpty()
                    ? round((float) $currentPassRates->avg(), 2)
                    : null,
            ],
            'students' => $studentRecords->all(),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  Collection<int, ExamAttempt>  $submittedAttempts
     * @return array<int, array<string, mixed>>
     */
    private function buildSubjectPerformance(Collection $submittedAttempts): array
    {
        return $submittedAttempts
            ->groupBy(fn (ExamAttempt $attempt) => $this->normalizeSubjectLabel($attempt->exam?->subject))
            ->map(function (Collection $attempts, string $label): array {
                $sortedAttempts = $attempts
                    ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
                    ->values();
                $scores = $this->extractScores($sortedAttempts);
                $latestAttempt = $sortedAttempts->first();
                $passingCount = $scores
                    ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
                    ->count();

                return [
                    'label' => $label,
                    'score' => $scores->isNotEmpty()
                        ? round((float) $scores->avg(), 2)
                        : null,
                    'average_score_percent' => $scores->isNotEmpty()
                        ? round((float) $scores->avg(), 2)
                        : null,
                    'latest_score_percent' => $latestAttempt
                        ? $this->scoreValue($latestAttempt)
                        : null,
                    'attempts_submitted' => (int) $sortedAttempts->count(),
                    'pass_rate_percent' => $sortedAttempts->isNotEmpty()
                        ? round(($passingCount / $sortedAttempts->count()) * 100, 2)
                        : null,
                    'last_submitted_at' => $latestAttempt?->submitted_at,
                ];
            })
            ->sortByDesc(fn (array $subject) => (float) ($subject['average_score_percent'] ?? -1))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ExamAttempt>  $attempts
     * @return Collection<int, float>
     */
    private function extractScores(Collection $attempts): Collection
    {
        return $attempts
            ->map(fn (ExamAttempt $attempt) => $this->scoreValue($attempt))
            ->filter(fn ($score) => !is_null($score))
            ->values();
    }

    private function scoreValue(ExamAttempt $attempt): ?float
    {
        return is_null($attempt->score_percent)
            ? null
            : round((float) $attempt->score_percent, 2);
    }

    private function normalizeSubjectLabel(?string $subject): string
    {
        $label = trim((string) ($subject ?? ''));

        return $label !== '' ? $label : 'Uncategorized';
    }

    private function sessionKey(int $examId, int $roomId): string
    {
        return $examId . ':' . $roomId;
    }

    private function attemptActivityTimestamp(ExamAttempt $attempt): int
    {
        return $attempt->submitted_at?->getTimestamp()
            ?? $attempt->started_at?->getTimestamp()
            ?? $attempt->created_at?->getTimestamp()
            ?? 0;
    }

    private function attemptSubmissionTimestamp(ExamAttempt $attempt): int
    {
        return $attempt->submitted_at?->getTimestamp()
            ?? $attempt->started_at?->getTimestamp()
            ?? $attempt->created_at?->getTimestamp()
            ?? 0;
    }

    private function dateValueTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        $timestamp = strtotime((string) ($value ?? ''));

        return $timestamp === false ? 0 : $timestamp;
    }

    public function buildItemDifficulty(Exam $exam): array
    {
        $attemptIds = ExamAttempt::where('exam_id', $exam->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->pluck('id');

        if ($attemptIds->isEmpty()) {
            return [];
        }

        $stats = DB::table('exam_attempt_answers')
            ->whereIn('exam_attempt_id', $attemptIds)
            ->select('question_bank_question_id', DB::raw('count(*) as total_attempts'), DB::raw('sum(is_correct) as correct_answers'))
            ->groupBy('question_bank_question_id')
            ->get();

        $questionIds = $stats->pluck('question_bank_question_id');
        $questions = DB::table('question_bank_questions')
            ->whereIn('id', $questionIds)
            ->get(['id', 'question_text', 'item_number', 'question_bank_id']);

        $results = [];
        foreach ($stats as $stat) {
            $question = $questions->firstWhere('id', $stat->question_bank_question_id);
            $total = (int) $stat->total_attempts;
            $correct = (int) $stat->correct_answers;
            $successRate = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
            $failureRate = 100 - $successRate;

            $results[] = [
                'question_id' => $stat->question_bank_question_id,
                'question_text' => $question ? $question->question_text : 'Unknown Question',
                'total_attempts' => $total,
                'correct_answers' => $correct,
                'success_rate_percent' => $successRate,
                'failure_rate_percent' => $failureRate,
            ];
        }

        usort($results, fn($a, $b) => $b['failure_rate_percent'] <=> $a['failure_rate_percent']);

        return $results;
    }
}
