<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use App\Services\Library\DocxQuestionParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $defaultPassword = Hash::make('pass');

        $students = collect();
        for ($index = 0; $index < 120; $index++) {
            $email = $index === 0
                ? 'student@example.com'
                : "student{$index}@example.com";

            $name = $index === 0
                ? 'Student User'
                : "Student User {$index}";

            $student = User::updateOrCreate([
                'email' => $email,
            ], [
                'name' => $name,
                'student_id' => (string) (2301290 + $index),
                'role' => User::ROLE_STUDENT,
                'year_level' => min(4, (int) floor($index / 30) + 1),
                'is_active' => true,
                'archived_at' => null,
                'password' => $defaultPassword,
            ]);

            $students->put($email, $student);
        }

        $archivedStudents = collect();
        for ($index = 1; $index <= 4; $index++) {
            $email = "graduate{$index}@example.com";

            $archivedStudent = User::updateOrCreate([
                'email' => $email,
            ], [
                'name' => "Graduate Student {$index}",
                'student_id' => (string) (2302000 + $index),
                'role' => User::ROLE_STUDENT,
                'year_level' => 4,
                'is_active' => true,
                'archived_at' => CarbonImmutable::parse('2026-03-01 09:00:00', 'UTC')->addDays($index),
                'password' => $defaultPassword,
            ]);

            $archivedStudents->put($email, $archivedStudent);
        }

        $teachers = collect();
        for ($index = 0; $index < 3; $index++) {
            $email = $index === 0
                ? 'teacher@example.com'
                : "teacher{$index}@example.com";

            $name = $index === 0
                ? 'Teacher User'
                : "Teacher User {$index}";

            $teacher = User::updateOrCreate([
                'email' => $email,
            ], [
                'name' => $name,
                'student_id' => null,
                'role' => User::ROLE_STAFF_MASTER_EXAMINER,
                'is_active' => true,
                'password' => $defaultPassword,
            ]);

            $teachers->put($email, $teacher);
        }

        User::updateOrCreate([
            'email' => 'admin@example.com',
        ], [
            'name' => 'Admin User',
            'student_id' => null,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => $defaultPassword,
        ]);

        $this->seedDemoDataset($students, $archivedStudents, $teachers);
    }

    /**
     * @param  Collection<string, User>  $students
     * @param  Collection<string, User>  $archivedStudents
     * @param  Collection<string, User>  $teachers
     */
    private function seedDemoDataset(Collection $students, Collection $archivedStudents, Collection $teachers): void
    {
        $teacher = $teachers->get('teacher@example.com');
        $teacherOne = $teachers->get('teacher1@example.com');
        $teacherTwo = $teachers->get('teacher2@example.com');

        if (!$teacher || !$teacherOne || !$teacherTwo) {
            return;
        }

        $seedQuestionTemplates = $this->loadSeedQuestionTemplates();

        $catalogingBank = $this->seedQuestionBank(
            $teacher,
            'Cataloging Foundations',
            'Cataloging and Classification',
            $seedQuestionTemplates,
        );
        $referenceBank = $this->seedQuestionBank(
            $teacher,
            'Reference Services Intensive',
            'Reference Services',
            $seedQuestionTemplates,
        );
        $libraryBank = $this->seedQuestionBank(
            $teacher,
            'Library Management Essentials',
            'Library Management',
            $seedQuestionTemplates,
        );
        $indexingBank = $this->seedQuestionBank(
            $teacherOne,
            'Indexing and Abstracting Drill',
            'Indexing and Abstracting',
            $seedQuestionTemplates,
        );
        $technologyBank = $this->seedQuestionBank(
            $teacherTwo,
            'Information Technology Basics',
            'Information Technology',
            $seedQuestionTemplates,
        );
        $archivesBank = $this->seedQuestionBank(
            $teacher,
            'Archival Studies Fundamentals',
            'Archival Studies',
            $seedQuestionTemplates,
        );

        $firstYearCurrentMembers = $this->usersForEmails(
            $students,
            array_merge(['student@example.com'], $this->studentEmailRange(1, 29)),
        );
        $secondYearCurrentMembers = $this->usersForEmails(
            $students,
            $this->studentEmailRange(30, 59),
        );
        $thirdYearCurrentMembers = $this->usersForEmails($students, $this->studentEmailRange(60, 89));
        $fourthYearCurrentMembers = $this->usersForEmails($students, $this->studentEmailRange(90, 119));

        $firstYearRoom = $this->seedRoom(
            $teacher,
            'BLIS 1A',
            'B1A26',
            [
                ...$firstYearCurrentMembers->all(),
            ],
        );
        $secondYearRoom = $this->seedRoom(
            $teacher,
            'BLIS 2A',
            'B2A26',
            [
                ...$secondYearCurrentMembers->all(),
            ],
        );
        $thirdYearRoom = $this->seedRoom(
            $teacherOne,
            'BLIS 3A',
            'B3A26',
            [
                ...$thirdYearCurrentMembers->all(),
            ],
        );
        $fourthYearRoom = $this->seedRoom(
            $teacherTwo,
            'BLIS 4A',
            'B4A26',
            [
                ...$fourthYearCurrentMembers->all(),
                ...$this->usersForEmails($archivedStudents, [
                    'graduate1@example.com',
                    'graduate2@example.com',
                    'graduate3@example.com',
                    'graduate4@example.com',
                ])->all(),
            ],
        );

        $catalogingExam = $this->seedExam(
            $teacher,
            'Cataloging Midterm Mock',
            $catalogingBank,
            6,
            45,
            CarbonImmutable::parse('2026-03-24 08:00:00', 'UTC'),
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
            [$firstYearRoom],
        );
        $referenceExam = $this->seedExam(
            $teacher,
            'Reference Services Mock',
            $referenceBank,
            6,
            40,
            CarbonImmutable::parse('2026-03-25 09:00:00', 'UTC'),
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
            [$firstYearRoom],
        );
        $libraryExam = $this->seedExam(
            $teacher,
            'Library Management Sprint',
            $libraryBank,
            5,
            30,
            CarbonImmutable::parse('2026-03-26 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
            [$firstYearRoom, $secondYearRoom],
        );
        $indexingExam = $this->seedExam(
            $teacherOne,
            'Indexing Drill Set',
            $indexingBank,
            6,
            35,
            CarbonImmutable::parse('2026-03-26 13:00:00', 'UTC'),
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
            [$thirdYearRoom, $firstYearRoom],
        );
        $archivesExam = $this->seedExam(
            $teacher,
            'Archives Practical Test',
            $archivesBank,
            6,
            40,
            CarbonImmutable::parse('2026-03-27 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
            [$firstYearRoom],
        );
        $technologyExam = $this->seedExam(
            $teacherTwo,
            'Information Technology Quiz',
            $technologyBank,
            6,
            35,
            CarbonImmutable::parse('2026-03-26 15:00:00', 'UTC'),
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
            [$fourthYearRoom, $firstYearRoom],
        );

        $this->seedArchivedExamAssignment(
            $referenceExam,
            $secondYearRoom,
            $teacher,
            CarbonImmutable::parse('2026-03-28 17:30:00', 'UTC'),
        );

        $this->seedAttempt(
            $catalogingExam,
            $firstYearRoom,
            $students->get('student@example.com'),
            CarbonImmutable::parse('2026-03-27 08:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, true, true, false]),
            CarbonImmutable::parse('2026-03-27 08:33:00', 'UTC'),
        );
        $this->seedAttempt(
            $catalogingExam,
            $firstYearRoom,
            $students->get('student1@example.com'),
            CarbonImmutable::parse('2026-03-27 09:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, true, true, true]),
            CarbonImmutable::parse('2026-03-27 09:29:00', 'UTC'),
        );
        $this->seedAttempt(
            $catalogingExam,
            $firstYearRoom,
            $students->get('student2@example.com'),
            CarbonImmutable::parse('2026-04-02 08:15:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, false]),
            null,
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
        );
        $this->seedAttempt(
            $catalogingExam,
            $firstYearRoom,
            $students->get('student3@example.com'),
            CarbonImmutable::parse('2026-03-28 07:45:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, false, true, false]),
            CarbonImmutable::parse('2026-03-28 08:20:00', 'UTC'),
        );

        $this->seedAttempt(
            $referenceExam,
            $firstYearRoom,
            $students->get('student@example.com'),
            CarbonImmutable::parse('2026-03-29 08:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, false, true, false]),
            CarbonImmutable::parse('2026-03-29 08:24:00', 'UTC'),
        );
        $this->seedAttempt(
            $referenceExam,
            $firstYearRoom,
            $students->get('student2@example.com'),
            CarbonImmutable::parse('2026-03-30 09:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, false, false, false]),
            CarbonImmutable::parse('2026-03-30 09:28:00', 'UTC'),
        );

        $this->seedAttempt(
            $libraryExam,
            $firstYearRoom,
            $students->get('student@example.com'),
            CarbonImmutable::parse('2026-04-02 10:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, false, true, false]),
            CarbonImmutable::parse('2026-04-02 10:25:00', 'UTC'),
        );
        $this->seedAttempt(
            $indexingExam,
            $firstYearRoom,
            $students->get('student@example.com'),
            CarbonImmutable::parse('2026-04-03 08:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, true, true, true]),
            CarbonImmutable::parse('2026-04-03 08:35:00', 'UTC'),
        );
        $this->seedAttempt(
            $technologyExam,
            $firstYearRoom,
            $students->get('student@example.com'),
            CarbonImmutable::parse('2026-04-04 09:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, false, false, false, true, false]),
            CarbonImmutable::parse('2026-04-04 09:20:00', 'UTC'),
        );
        $this->seedAttempt(
            $archivesExam,
            $firstYearRoom,
            $students->get('student@example.com'),
            CarbonImmutable::parse('2026-04-05 09:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, false, false, false]),
            CarbonImmutable::parse('2026-04-05 09:30:00', 'UTC'),
        );
        $this->seedAttempt(
            $libraryExam,
            $secondYearRoom,
            $students->get('student30@example.com'),
            CarbonImmutable::parse('2026-03-31 10:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, true, false]),
            CarbonImmutable::parse('2026-03-31 10:18:00', 'UTC'),
        );
        $this->seedAttempt(
            $libraryExam,
            $secondYearRoom,
            $students->get('student31@example.com'),
            CarbonImmutable::parse('2026-04-01 10:15:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, false, true, false]),
            CarbonImmutable::parse('2026-04-01 10:38:00', 'UTC'),
        );

        $this->seedAttempt(
            $indexingExam,
            $thirdYearRoom,
            $students->get('student67@example.com'),
            CarbonImmutable::parse('2026-03-30 13:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, true, true, false]),
            CarbonImmutable::parse('2026-03-30 13:24:00', 'UTC'),
        );
        $this->seedAttempt(
            $indexingExam,
            $thirdYearRoom,
            $students->get('student69@example.com'),
            CarbonImmutable::parse('2026-04-02 13:30:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, false]),
            null,
            CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC'),
        );

        $this->seedAttempt(
            $technologyExam,
            $fourthYearRoom,
            $students->get('student100@example.com'),
            CarbonImmutable::parse('2026-03-31 15:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, false, true, false]),
            CarbonImmutable::parse('2026-03-31 15:26:00', 'UTC'),
        );
        $this->seedAttempt(
            $technologyExam,
            $fourthYearRoom,
            $students->get('student101@example.com'),
            CarbonImmutable::parse('2026-04-01 15:00:00', 'UTC'),
            $this->buildAttemptSelections($seedQuestionTemplates, [true, true, true, true, true, false]),
            CarbonImmutable::parse('2026-04-01 15:22:00', 'UTC'),
        );

        $this->seedSyntheticAttempts(
            $catalogingExam,
            $firstYearRoom,
            $firstYearCurrentMembers->slice(4, 12)->values(),
            $seedQuestionTemplates,
            CarbonImmutable::parse('2026-03-27 10:00:00', 'UTC'),
            [
                ['submitted' => true, 'answered' => 6, 'correct' => 6],
                ['submitted' => true, 'answered' => 6, 'correct' => 5],
                ['submitted' => true, 'answered' => 6, 'correct' => 4],
                ['submitted' => false, 'answered' => 3, 'correct' => 2],
            ],
        );
        $this->seedSyntheticAttempts(
            $referenceExam,
            $firstYearRoom,
            $firstYearCurrentMembers->slice(9, 10)->values(),
            $seedQuestionTemplates,
            CarbonImmutable::parse('2026-03-29 09:00:00', 'UTC'),
            [
                ['submitted' => true, 'answered' => 6, 'correct' => 5],
                ['submitted' => true, 'answered' => 6, 'correct' => 4],
                ['submitted' => true, 'answered' => 6, 'correct' => 3],
                ['submitted' => false, 'answered' => 2, 'correct' => 1],
            ],
        );
        $this->seedSyntheticAttempts(
            $libraryExam,
            $secondYearRoom,
            $secondYearCurrentMembers->slice(6, 10)->values(),
            $seedQuestionTemplates,
            CarbonImmutable::parse('2026-03-31 11:00:00', 'UTC'),
            [
                ['submitted' => true, 'answered' => 5, 'correct' => 5],
                ['submitted' => true, 'answered' => 5, 'correct' => 4],
                ['submitted' => true, 'answered' => 5, 'correct' => 3],
                ['submitted' => false, 'answered' => 2, 'correct' => 1],
            ],
        );
        $this->seedSyntheticAttempts(
            $indexingExam,
            $thirdYearRoom,
            $thirdYearCurrentMembers->slice(2, 10)->values(),
            $seedQuestionTemplates,
            CarbonImmutable::parse('2026-03-30 14:00:00', 'UTC'),
            [
                ['submitted' => true, 'answered' => 6, 'correct' => 6],
                ['submitted' => true, 'answered' => 6, 'correct' => 5],
                ['submitted' => true, 'answered' => 6, 'correct' => 4],
                ['submitted' => false, 'answered' => 3, 'correct' => 2],
            ],
        );
        $this->seedSyntheticAttempts(
            $technologyExam,
            $fourthYearRoom,
            $fourthYearCurrentMembers->slice(1, 10)->values(),
            $seedQuestionTemplates,
            CarbonImmutable::parse('2026-03-31 16:00:00', 'UTC'),
            [
                ['submitted' => true, 'answered' => 6, 'correct' => 5],
                ['submitted' => true, 'answered' => 6, 'correct' => 4],
                ['submitted' => true, 'answered' => 6, 'correct' => 3],
                ['submitted' => false, 'answered' => 2, 'correct' => 1],
            ],
        );

        $this->seedAuditLog(
            $teacher,
            'room.create',
            'room',
            $firstYearRoom->id,
            'Created demo room BLIS 1A',
            ['code' => $firstYearRoom->code],
            CarbonImmutable::parse('2026-03-24 07:30:00', 'UTC'),
        );
        $this->seedAuditLog(
            $teacher,
            'room.create',
            'room',
            $secondYearRoom->id,
            'Created demo room BLIS 2A',
            ['code' => $secondYearRoom->code],
            CarbonImmutable::parse('2026-03-24 07:45:00', 'UTC'),
        );
        $this->seedAuditLog(
            $teacher,
            'exam.create',
            'exam',
            $catalogingExam->id,
            'Created demo exam Cataloging Midterm Mock',
            ['title' => $catalogingExam->title],
            CarbonImmutable::parse('2026-03-24 08:10:00', 'UTC'),
        );
        $this->seedAuditLog(
            $teacher,
            'exam.create',
            'exam',
            $referenceExam->id,
            'Created demo exam Reference Services Mock',
            ['title' => $referenceExam->title],
            CarbonImmutable::parse('2026-03-25 08:10:00', 'UTC'),
        );
        $this->seedAuditLog(
            $teacher,
            'report.export.summary',
            'exam',
            $catalogingExam->id,
            'Exported a demo summary report',
            ['exam_id' => $catalogingExam->id, 'room_id' => $firstYearRoom->id],
            CarbonImmutable::parse('2026-04-02 12:00:00', 'UTC'),
        );

        $this->seedAuditLog(
            $teacherOne,
            'room.create',
            'room',
            $thirdYearRoom->id,
            'Created demo room BLIS 3A',
            ['code' => $thirdYearRoom->code],
            CarbonImmutable::parse('2026-03-26 12:30:00', 'UTC'),
        );
        $this->seedAuditLog(
            $teacherOne,
            'exam.create',
            'exam',
            $indexingExam->id,
            'Created demo exam Indexing Drill Set',
            ['title' => $indexingExam->title],
            CarbonImmutable::parse('2026-03-26 13:10:00', 'UTC'),
        );

        $this->seedAuditLog(
            $teacherTwo,
            'room.create',
            'room',
            $fourthYearRoom->id,
            'Created demo room BLIS 4A',
            ['code' => $fourthYearRoom->code],
            CarbonImmutable::parse('2026-03-26 14:30:00', 'UTC'),
        );
        $this->seedAuditLog(
            $teacherTwo,
            'exam.create',
            'exam',
            $technologyExam->id,
            'Created demo exam Information Technology Quiz',
            ['title' => $technologyExam->title],
            CarbonImmutable::parse('2026-03-26 15:10:00', 'UTC'),
        );
    }

    /**
     * @param  array<int, User|null>  $members
     */
    private function seedRoom(User $creator, string $name, string $code, array $members): Room
    {
        $room = Room::updateOrCreate([
            'code' => $code,
        ], [
            'name' => $name,
            'created_by' => $creator->id,
        ]);

        $memberIds = collect($members)
            ->filter(fn ($member) => $member instanceof User)
            ->map(fn (User $member) => $member->id)
            ->values()
            ->all();

        $room->members()->sync($memberIds);

        return $room->refresh();
    }

    /**
     * @param  array<int, Room>  $rooms
     */
    private function seedExam(
        User $creator,
        string $title,
        QuestionBank $questionBank,
        int $totalItems,
        int $durationMinutes,
        CarbonImmutable $scheduleStartAt,
        CarbonImmutable $scheduleEndAt,
        array $rooms,
    ): Exam {
        $exam = Exam::updateOrCreate([
            'title' => $title,
            'created_by' => $creator->id,
        ], [
            'subject' => $questionBank->subject,
            'description' => 'Seeded demo exam for dashboard and reporting verification.',
            'question_bank_id' => $questionBank->id,
            'total_items' => $totalItems,
            'duration_minutes' => $durationMinutes,
            'scheduled_at' => $scheduleStartAt,
            'schedule_start_at' => $scheduleStartAt,
            'schedule_end_at' => $scheduleEndAt,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
        ]);

        $exam->syncQuestionBanks([$questionBank->id]);

        $now = CarbonImmutable::parse('2026-03-24 00:00:00', 'UTC');
        $syncPayload = collect($rooms)
            ->mapWithKeys(fn (Room $room) => [$room->id => [
                'assigned_by' => $creator->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]])
            ->all();

        $exam->rooms()->sync($syncPayload);

        return $exam->refresh();
    }

    private function seedQuestionBank(
        User $creator,
        string $title,
        string $subject,
        array $questionTemplates,
    ): QuestionBank {
        $questionBank = QuestionBank::updateOrCreate([
            'title' => $title,
            'created_by' => $creator->id,
        ], [
            'subject' => $subject,
            'source_filename' => basename($this->seedQuestionTemplatePath()),
            'total_items' => count($questionTemplates),
        ]);

        $keptQuestionIds = [];

        foreach ($questionTemplates as $template) {
            $question = QuestionBankQuestion::updateOrCreate([
                'question_bank_id' => $questionBank->id,
                'item_number' => $template['item_number'],
            ], [
                'question_text' => $template['question_text'],
                'question_type' => $template['question_type'],
                'answer_label' => $template['answer_label'],
                'answer_text' => $template['answer_text'],
            ]);

            $keptQuestionIds[] = $question->id;
            $keptOptionLabels = [];

            foreach ($template['options'] as $index => $option) {
                $keptOptionLabels[] = $option['label'];

                QuestionBankOption::updateOrCreate([
                    'question_bank_question_id' => $question->id,
                    'option_label' => $option['label'],
                ], [
                    'sort_order' => $index + 1,
                    'option_text' => $option['text'],
                    'is_correct' => (bool) $option['is_correct'],
                ]);
            }

            $question->options()
                ->whereNotIn('option_label', $keptOptionLabels)
                ->delete();
        }

        $questionBank->questions()
            ->whereNotIn('id', $keptQuestionIds)
            ->delete();

        return $questionBank->refresh();
    }

    private function seedArchivedExamAssignment(
        Exam $exam,
        Room $room,
        User $actor,
        CarbonImmutable $archivedAt,
    ): void {
        DB::table('exam_room')->updateOrInsert(
            [
                'exam_id' => $exam->id,
                'room_id' => $room->id,
            ],
            [
                'assigned_by' => $actor->id,
                'archived_at' => $archivedAt,
                'archived_by' => $actor->id,
                'created_at' => $archivedAt->subDays(7),
                'updated_at' => $archivedAt,
            ],
        );
    }

    /**
     * @param  array<int, string>  $selectedOptionLabels
     */
    private function seedAttempt(
        Exam $exam,
        Room $room,
        ?User $student,
        CarbonImmutable $startedAt,
        array $selectedOptionLabels,
        ?CarbonImmutable $submittedAt = null,
        ?CarbonImmutable $expiresAt = null,
    ): ?ExamAttempt {
        if (!$student) {
            return null;
        }

        /** @var QuestionBank|null $primaryBank */
        $primaryBank = $exam->resolvedQuestionBanks()->first();
        if (!$primaryBank) {
            return null;
        }

        $questions = $primaryBank->questions()
            ->with('options')
            ->orderBy('item_number')
            ->limit((int) $exam->total_items)
            ->get();

        $attempt = ExamAttempt::updateOrCreate([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'started_at' => $startedAt,
        ], [
            'status' => $submittedAt ? ExamAttempt::STATUS_SUBMITTED : ExamAttempt::STATUS_IN_PROGRESS,
            'total_items' => (int) $exam->total_items,
            'duration_minutes' => (int) $exam->duration_minutes,
            'answered_count' => 0,
            'correct_answers' => 0,
            'score_percent' => null,
            'expires_at' => $submittedAt ? null : ($expiresAt ?? $startedAt->addMinutes((int) $exam->duration_minutes)),
            'submitted_at' => $submittedAt,
        ]);

        ExamAttemptAnswer::query()
            ->where('exam_attempt_id', $attempt->id)
            ->delete();
        ExamAttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->delete();

        $answeredCount = 0;
        $correctAnswers = 0;

        foreach ($questions as $index => $question) {
            $itemNumber = $index + 1;

            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'item_number' => $itemNumber,
                'is_bookmarked' => false,
            ]);

            $selectedLabel = $selectedOptionLabels[$itemNumber] ?? null;
            if (!$selectedLabel) {
                continue;
            }

            /** @var QuestionBankOption|null $selectedOption */
            $selectedOption = $question->options
                ->firstWhere('option_label', $selectedLabel);

            if (!$selectedOption) {
                continue;
            }

            $answeredCount++;
            if ((bool) $selectedOption->is_correct) {
                $correctAnswers++;
            }

            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'question_bank_option_id' => $selectedOption->id,
                'answer_text' => null,
                'is_correct' => (bool) $selectedOption->is_correct,
                'answered_at' => $startedAt->addMinutes($itemNumber * 2),
            ]);
        }

        $attempt->answered_count = $answeredCount;
        $attempt->correct_answers = $correctAnswers;
        $attempt->score_percent = $submittedAt && $exam->total_items > 0
            ? round(($correctAnswers / (int) $exam->total_items) * 100, 2)
            : null;
        $attempt->save();

        return $attempt->refresh();
    }

    private function seedAuditLog(
        User $actor,
        string $action,
        string $targetType,
        int $targetId,
        string $description,
        array $metadata,
        CarbonImmutable $createdAt,
    ): void {
        AuditLog::query()->updateOrCreate([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ], [
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => '127.0.0.1',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * @param  array<int, array{
     *   options: array<int, array{label: string, text: string, is_correct: bool}>
     * }>  $questionTemplates
     * @param  array<int, bool>  $correctnessPattern
     * @return array<int, string>
     */
    private function buildAttemptSelections(array $questionTemplates, array $correctnessPattern): array
    {
        $selections = [];

        foreach (array_values($correctnessPattern) as $index => $shouldBeCorrect) {
            $template = $questionTemplates[$index] ?? null;
            if (!is_array($template)) {
                continue;
            }

            $options = collect($template['options'] ?? []);
            if ($options->isEmpty()) {
                continue;
            }

            $selectedOption = $shouldBeCorrect
                ? $options->first(fn (array $option): bool => (bool) ($option['is_correct'] ?? false))
                : $options->first(fn (array $option): bool => !(bool) ($option['is_correct'] ?? false));

            if (!is_array($selectedOption) || !isset($selectedOption['label'])) {
                $selectedOption = $options->first();
            }

            if (!is_array($selectedOption) || !isset($selectedOption['label'])) {
                continue;
            }

            $selections[$index + 1] = $selectedOption['label'];
        }

        return $selections;
    }

    /**
     * @return array<int, bool>
     */
    private function buildCorrectnessPattern(int $totalItems, int $correctItems, ?int $answeredItems = null): array
    {
        $boundedTotalItems = max(0, $totalItems);
        $boundedAnsweredItems = max(0, min($boundedTotalItems, $answeredItems ?? $boundedTotalItems));
        $boundedCorrectItems = max(0, min($boundedAnsweredItems, $correctItems));

        $pattern = [];

        for ($index = 0; $index < $boundedAnsweredItems; $index++) {
            $pattern[] = $index < $boundedCorrectItems;
        }

        return $pattern;
    }

    /**
     * @return array<int, string>
     */
    private function studentEmailRange(int $start, int $end): array
    {
        if ($end < $start) {
            return [];
        }

        return collect(range($start, $end))
            ->map(fn (int $index): string => "student{$index}@example.com")
            ->all();
    }

    /**
     * @param  Collection<string, User>  $users
     * @param  array<int, string>  $emails
     * @return Collection<int, User>
     */
    private function usersForEmails(Collection $users, array $emails): Collection
    {
        return collect($emails)
            ->map(fn (string $email) => $users->get($email))
            ->filter(fn ($user) => $user instanceof User)
            ->values();
    }

    /**
     * @param  Collection<int, User>  $students
     * @param  array<int, array{submitted: bool, answered: int, correct: int}>  $attemptBlueprints
     * @param  array<int, array{
     *   options: array<int, array{label: string, text: string, is_correct: bool}>
     * }>  $questionTemplates
     */
    private function seedSyntheticAttempts(
        Exam $exam,
        Room $room,
        Collection $students,
        array $questionTemplates,
        CarbonImmutable $startedAt,
        array $attemptBlueprints,
    ): void {
        if ($students->isEmpty() || $attemptBlueprints === []) {
            return;
        }

        foreach ($students->values() as $index => $student) {
            $blueprint = $attemptBlueprints[$index % count($attemptBlueprints)];
            $answeredItems = max(0, min((int) $exam->total_items, (int) ($blueprint['answered'] ?? $exam->total_items)));
            $correctItems = max(0, min($answeredItems, (int) ($blueprint['correct'] ?? $answeredItems)));
            $attemptStartedAt = $startedAt->addMinutes($index * 19);
            $submittedAt = (bool) ($blueprint['submitted'] ?? true)
                ? $attemptStartedAt->addMinutes(min(max(12, (int) $exam->duration_minutes - 4), 18 + (($index % 4) * 3)))
                : null;

            $this->seedAttempt(
                $exam,
                $room,
                $student,
                $attemptStartedAt,
                $this->buildAttemptSelections(
                    $questionTemplates,
                    $this->buildCorrectnessPattern((int) $exam->total_items, $correctItems, $answeredItems),
                ),
                $submittedAt,
                $submittedAt ? null : $attemptStartedAt->addMinutes((int) $exam->duration_minutes),
            );
        }
    }

    private function seedQuestionTemplatePath(): string
    {
        return base_path('tmp/question-set-tests/INDEXING-AND-ABSTRACTING.docx');
    }

    /**
     * @return array<int, array{
     *   item_number: int,
     *   question_text: string,
     *   question_type: string,
     *   answer_label: ?string,
     *   answer_text: ?string,
     *   options: array<int, array{label: string, text: string, is_correct: bool}>
     * }>
     */
    private function loadSeedQuestionTemplates(): array
    {
        $templatePath = $this->seedQuestionTemplatePath();
        if (!is_file($templatePath)) {
            throw new RuntimeException("Seed question bank template is missing: {$templatePath}");
        }

        $parsed = app(DocxQuestionParser::class)->parse($templatePath);

        $templates = collect($parsed['questions'] ?? [])
            ->map(function (array $question): array {
                $options = collect($question['options'] ?? [])
                    ->map(function (array $option, int $optionIndex): array {
                        $defaultLabel = chr(ord('A') + min($optionIndex, 25));

                        return [
                            'label' => strtoupper(trim((string) ($option['label'] ?? $defaultLabel))),
                            'text' => trim((string) ($option['text'] ?? '')),
                            'is_correct' => (bool) ($option['is_correct'] ?? false),
                        ];
                    })
                    ->filter(fn (array $option): bool => $option['text'] !== '')
                    ->values();

                $questionType = (string) ($question['question_type'] ?? QuestionBankQuestion::TYPE_MULTIPLE_CHOICE);
                if (!in_array($questionType, QuestionBankQuestion::TYPES, true)) {
                    $questionType = QuestionBankQuestion::TYPE_MULTIPLE_CHOICE;
                }

                $answerLabel = strtoupper(trim((string) ($question['answer_label'] ?? '')));
                if ($answerLabel === '' && $options->isNotEmpty()) {
                    $firstCorrectOption = $options->first(fn (array $option): bool => $option['is_correct']);
                    $answerLabel = is_array($firstCorrectOption)
                        ? $firstCorrectOption['label']
                        : '';
                }

                if ($answerLabel !== '') {
                    $options = $options->map(function (array $option) use ($answerLabel): array {
                        $option['is_correct'] = $option['label'] === $answerLabel;

                        return $option;
                    })->values();
                }

                $resolvedCorrectOption = $options->first(fn (array $option): bool => $option['is_correct']);
                $answerText = is_array($resolvedCorrectOption)
                    ? $resolvedCorrectOption['text']
                    : trim((string) ($question['answer_text'] ?? ''));

                if ($options->isEmpty()) {
                    $questionType = QuestionBankQuestion::TYPE_OPEN_ENDED;
                    $answerLabel = '';
                }

                return [
                    'question_text' => trim((string) ($question['text'] ?? '')),
                    'question_type' => $questionType,
                    'answer_label' => $answerLabel !== '' ? $answerLabel : null,
                    'answer_text' => $answerText !== '' ? $answerText : null,
                    'options' => $options->all(),
                ];
            })
            ->filter(function (array $question): bool {
                if ($question['question_text'] === '') {
                    return false;
                }

                if ($question['question_type'] === QuestionBankQuestion::TYPE_OPEN_ENDED) {
                    return true;
                }

                return count($question['options']) >= 2 && $question['answer_label'] !== null;
            })
            ->values()
            ->map(function (array $question, int $index): array {
                $question['item_number'] = $index + 1;

                return $question;
            })
            ->all();

        if ($templates === []) {
            throw new RuntimeException('The seed question bank template did not produce any usable questions.');
        }

        return $templates;
    }
}
