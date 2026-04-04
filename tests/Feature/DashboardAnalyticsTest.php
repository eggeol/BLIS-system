<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_analytics_overview_returns_live_attempt_metrics(): void
    {
        $staff = $this->makeUser('staff@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('student@example.com', User::ROLE_STUDENT, '2301400');

        $roomA = $this->makeRoom($staff, 'Room A', 'ROOMA1');
        $roomB = $this->makeRoom($staff, 'Room B', 'ROOMB1');

        $roomA->members()->attach($student->id);
        $roomB->members()->attach($student->id);

        $examA = $this->makeExam($staff, 'Cataloging Mock 1', 'Cataloging');
        $examB = $this->makeExam($staff, 'Reference Mastery', 'Reference Services');
        $examC = $this->makeExam($staff, 'Library Management Sprint', 'Library Management');

        $this->assignExamToRoom($examA, $roomA, $staff);
        $this->assignExamToRoom($examB, $roomA, $staff);
        $this->assignExamToRoom($examC, $roomB, $staff);

        ExamAttempt::create([
            'exam_id' => $examA->id,
            'room_id' => $roomA->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 40,
            'score_percent' => 80,
            'started_at' => now()->subDays(5),
            'submitted_at' => now()->subDays(5)->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $examB->id,
            'room_id' => $roomA->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 30,
            'score_percent' => 60,
            'started_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(2)->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $examC->id,
            'room_id' => $roomB->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_IN_PROGRESS,
            'total_items' => 40,
            'duration_minutes' => 45,
            'answered_count' => 10,
            'correct_answers' => 0,
            'score_percent' => null,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/student/analytics/overview');

        $response
            ->assertOk()
            ->assertJsonPath('summary.rooms_joined', 2)
            ->assertJsonPath('summary.available_exams', 3)
            ->assertJsonPath('summary.pending_exams', 1)
            ->assertJsonPath('summary.attempts_started', 3)
            ->assertJsonPath('summary.attempts_submitted', 2)
            ->assertJsonPath('summary.completed_exams', 2)
            ->assertJsonPath('summary.in_progress_attempts', 1)
            ->assertJsonPath('summary.passing_attempts', 1)
            ->assertJsonPath('summary.failing_attempts', 1)
            ->assertJsonPath('summary.average_score_percent', 70)
            ->assertJsonPath('summary.pass_rate_percent', 50);

        $subjects = collect($response->json('subjects'));
        $this->assertEquals(80.0, $subjects->firstWhere('label', 'Cataloging')['score']);
        $this->assertEquals(60.0, $subjects->firstWhere('label', 'Reference Services')['score']);

        $focusSubjects = collect($response->json('focus_subjects'));
        $this->assertSame('Reference Services', $focusSubjects->first()['label']);

        $history = $response->json('score_history');
        $this->assertCount(2, $history);
        $this->assertSame('Cataloging Mock 1', $history[0]['label']);
        $this->assertSame('Reference Mastery', $history[1]['label']);

        $activity = $response->json('recent_activity');
        $this->assertSame('in_progress', $activity[0]['status']);
        $this->assertSame('Library Management Sprint', $activity[0]['title']);
    }

    public function test_staff_report_overview_uses_latest_attempt_per_student_session(): void
    {
        $staff = $this->makeUser('teacher@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $studentOne = $this->makeUser('student1@example.com', User::ROLE_STUDENT, '2301501');
        $studentTwo = $this->makeUser('student2@example.com', User::ROLE_STUDENT, '2301502');
        $studentThree = $this->makeUser('student3@example.com', User::ROLE_STUDENT, '2301503');

        $roomAlpha = $this->makeRoom($staff, 'Alpha Room', 'ALPHA1');
        $roomBeta = $this->makeRoom($staff, 'Beta Room', 'BETA01');

        $roomAlpha->members()->attach([$studentOne->id, $studentTwo->id]);
        $roomBeta->members()->attach([$studentThree->id]);

        $examAlpha = $this->makeExam($staff, 'Cataloging Comprehensive', 'Cataloging');
        $examBeta = $this->makeExam($staff, 'Reference Review', 'Reference Services');

        $this->assignExamToRoom($examAlpha, $roomAlpha, $staff);
        $this->assignExamToRoom($examBeta, $roomBeta, $staff);

        ExamAttempt::create([
            'exam_id' => $examAlpha->id,
            'room_id' => $roomAlpha->id,
            'user_id' => $studentOne->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 40,
            'score_percent' => 80,
            'started_at' => now()->subDays(4),
            'submitted_at' => now()->subDays(4)->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $examAlpha->id,
            'room_id' => $roomAlpha->id,
            'user_id' => $studentOne->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 45,
            'score_percent' => 90,
            'started_at' => now()->subDays(1),
            'submitted_at' => now()->subDays(1)->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $examAlpha->id,
            'room_id' => $roomAlpha->id,
            'user_id' => $studentTwo->id,
            'status' => ExamAttempt::STATUS_IN_PROGRESS,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 20,
            'correct_answers' => 0,
            'score_percent' => null,
            'started_at' => now()->subHours(3),
            'expires_at' => now()->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $examBeta->id,
            'room_id' => $roomBeta->id,
            'user_id' => $studentThree->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 40,
            'duration_minutes' => 45,
            'answered_count' => 40,
            'correct_answers' => 28,
            'score_percent' => 70,
            'started_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(2)->addMinutes(50),
        ]);

        AuditLog::create([
            'actor_id' => $staff->id,
            'action' => 'report.export.summary',
            'target_type' => 'exam',
            'target_id' => $examAlpha->id,
            'description' => 'Exported a summary report',
            'metadata' => ['exam_id' => $examAlpha->id],
            'ip_address' => '127.0.0.1',
        ]);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/reports/overview');

        $response
            ->assertOk()
            ->assertJsonPath('metrics.managed_rooms', 2)
            ->assertJsonPath('metrics.managed_exams', 2)
            ->assertJsonPath('metrics.students_enrolled', 3)
            ->assertJsonPath('metrics.exam_assignments', 2)
            ->assertJsonPath('metrics.session_enrollments', 3)
            ->assertJsonPath('metrics.attempts_started', 3)
            ->assertJsonPath('metrics.attempts_submitted', 2)
            ->assertJsonPath('metrics.completion_rate_percent', 66.67)
            ->assertJsonPath('metrics.average_score_percent', 80)
            ->assertJsonPath('metrics.pass_rate_percent', 50);

        $sessionPerformance = collect($response->json('session_performance'));
        $catalogingSession = $sessionPerformance->firstWhere('exam_title', 'Cataloging Comprehensive');

        $this->assertSame(2, $catalogingSession['students_total']);
        $this->assertSame(2, $catalogingSession['students_started']);
        $this->assertSame(1, $catalogingSession['students_submitted']);
        $this->assertEquals(50.0, $catalogingSession['completion_rate_percent']);
        $this->assertEquals(90.0, $catalogingSession['average_score_percent']);
        $this->assertEquals(100.0, $catalogingSession['pass_rate_percent']);

        $subjectPerformance = collect($response->json('subject_performance'));
        $this->assertEquals(90.0, $subjectPerformance->firstWhere('label', 'Cataloging')['score']);
        $this->assertEquals(70.0, $subjectPerformance->firstWhere('label', 'Reference Services')['score']);

        $this->assertSame('Exported a summary report', $response->json('recent_activity.0.description'));
    }

    public function test_student_analytics_overview_ignores_archived_room_exam_assignments(): void
    {
        $staff = $this->makeUser('staff-archive@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('student-archive@example.com', User::ROLE_STUDENT, '2301600');

        $room = $this->makeRoom($staff, 'Archive Room', 'ARCH01');
        $room->members()->attach($student->id);

        $activeExam = $this->makeExam($staff, 'Active Exam', 'Cataloging');
        $archivedExam = $this->makeExam($staff, 'Archived Exam', 'Reference Services');

        $this->assignExamToRoom($activeExam, $room, $staff);
        $this->assignExamToRoom($archivedExam, $room, $staff, now());

        Sanctum::actingAs($student);

        $this->getJson('/api/student/analytics/overview')
            ->assertOk()
            ->assertJsonPath('summary.rooms_joined', 1)
            ->assertJsonPath('summary.available_exams', 1)
            ->assertJsonPath('summary.pending_exams', 1);
    }

    public function test_staff_report_overview_excludes_archived_students_and_archived_exam_sessions(): void
    {
        $staff = $this->makeUser('staff-filtered@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $currentStudent = $this->makeUser('current-student@example.com', User::ROLE_STUDENT, '2301701');
        $archivedStudent = $this->makeUser('archived-student@example.com', User::ROLE_STUDENT, '2301702');
        $archivedStudent->forceFill(['archived_at' => now()->subMonth()])->save();

        $room = $this->makeRoom($staff, 'Filtered Room', 'FILT01');
        $room->members()->attach([$currentStudent->id, $archivedStudent->id]);

        $activeExam = $this->makeExam($staff, 'Current Session Exam', 'Cataloging');
        $archivedExam = $this->makeExam($staff, 'Archived Session Exam', 'Reference Services');

        $this->assignExamToRoom($activeExam, $room, $staff);
        $this->assignExamToRoom($archivedExam, $room, $staff, now()->subWeek());

        ExamAttempt::create([
            'exam_id' => $activeExam->id,
            'room_id' => $room->id,
            'user_id' => $currentStudent->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 40,
            'score_percent' => 80,
            'started_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(2)->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $activeExam->id,
            'room_id' => $room->id,
            'user_id' => $archivedStudent->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 45,
            'score_percent' => 90,
            'started_at' => now()->subDays(1),
            'submitted_at' => now()->subDays(1)->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $archivedExam->id,
            'room_id' => $room->id,
            'user_id' => $currentStudent->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 30,
            'score_percent' => 60,
            'started_at' => now()->subDays(3),
            'submitted_at' => now()->subDays(3)->addHour(),
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('metrics.managed_rooms', 1)
            ->assertJsonPath('metrics.managed_exams', 2)
            ->assertJsonPath('metrics.students_enrolled', 1)
            ->assertJsonPath('metrics.exam_assignments', 1)
            ->assertJsonPath('metrics.session_enrollments', 1)
            ->assertJsonPath('metrics.attempts_started', 1)
            ->assertJsonPath('metrics.attempts_submitted', 1)
            ->assertJsonPath('metrics.average_score_percent', 80)
            ->assertJsonPath('metrics.pass_rate_percent', 100);
    }

    public function test_student_analytics_route_is_for_student_accounts_only(): void
    {
        $staff = $this->makeUser('staff-only@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        Sanctum::actingAs($staff);

        $this->getJson('/api/student/analytics/overview')
            ->assertForbidden();
    }

    public function test_student_cannot_join_room_for_a_different_year_level(): void
    {
        $staff = $this->makeUser('staff-join@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $yearOneStudent = $this->makeUser('student-year1@example.com', User::ROLE_STUDENT, '2301801', 1);
        $yearTwoStudent = $this->makeUser('student-year2@example.com', User::ROLE_STUDENT, '2301802', 2);

        $room = $this->makeRoom($staff, 'BLIS 1A', 'B1JOIN');
        $room->members()->attach($yearOneStudent->id);

        Sanctum::actingAs($yearTwoStudent);

        $this->postJson('/api/rooms/join', ['code' => $room->code])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can only join rooms that match your year level section.');
    }

    public function test_student_can_join_room_when_year_level_matches_section(): void
    {
        $staff = $this->makeUser('staff-join-ok@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $existingStudent = $this->makeUser('student-existing@example.com', User::ROLE_STUDENT, '2301803', 3);
        $joiningStudent = $this->makeUser('student-joining@example.com', User::ROLE_STUDENT, '2301804', 3);

        $room = $this->makeRoom($staff, 'BLIS 3A', 'B3JOIN');
        $room->members()->attach($existingStudent->id);

        Sanctum::actingAs($joiningStudent);

        $this->postJson('/api/rooms/join', ['code' => $room->code])
            ->assertOk()
            ->assertJsonPath('message', 'Joined room successfully');

        $this->assertDatabaseHas('room_user', [
            'room_id' => $room->id,
            'user_id' => $joiningStudent->id,
        ]);
    }

    public function test_staff_student_directory_returns_global_student_records_with_performance(): void
    {
        $staff = $this->makeUser('staff-directory@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $currentStudent = $this->makeUser('current-directory@example.com', User::ROLE_STUDENT, '2301805', 1);
        $currentStudentTwo = $this->makeUser('current-directory-2@example.com', User::ROLE_STUDENT, '2301806', 2);
        $archivedStudent = $this->makeUser('archived-directory@example.com', User::ROLE_STUDENT, '2301807', 4);
        $archivedStudent->forceFill(['archived_at' => now()->subMonth()])->save();

        $room = $this->makeRoom($staff, 'BLIS 1A', 'DIR001');
        $room->members()->attach([$currentStudent->id, $currentStudentTwo->id, $archivedStudent->id]);

        $exam = $this->makeExam($staff, 'Cataloging Mastery', 'Cataloging');
        $this->assignExamToRoom($exam, $room, $staff);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $currentStudent->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 40,
            'score_percent' => 80,
            'started_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(2)->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $currentStudentTwo->id,
            'status' => ExamAttempt::STATUS_IN_PROGRESS,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 10,
            'correct_answers' => 0,
            'score_percent' => null,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
        ]);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $archivedStudent->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 45,
            'score_percent' => 90,
            'started_at' => now()->subDays(3),
            'submitted_at' => now()->subDays(3)->addHour(),
        ]);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/students/directory');

        $response
            ->assertOk()
            ->assertJsonPath('summary.current_students', 2)
            ->assertJsonPath('summary.archived_students', 1)
            ->assertJsonPath('summary.students_with_results', 1)
            ->assertJsonPath('summary.average_score_percent', 80)
            ->assertJsonPath('summary.average_pass_rate_percent', 100);

        $students = collect($response->json('students'));
        $currentRecord = $students->firstWhere('email', 'current-directory@example.com');
        $archivedRecord = $students->firstWhere('email', 'archived-directory@example.com');

        $this->assertSame(['BLIS 1A'], $currentRecord['room_names']);
        $this->assertSame(1, $currentRecord['attempts_submitted']);
        $this->assertEquals(80.0, $currentRecord['average_score_percent']);
        $this->assertNotNull($archivedRecord['archived_at']);
        $this->assertEquals(90.0, $archivedRecord['average_score_percent']);
    }

    public function test_student_directory_route_is_not_available_to_student_accounts(): void
    {
        $student = $this->makeUser('student-directory-forbidden@example.com', User::ROLE_STUDENT, '2301808', 1);

        Sanctum::actingAs($student);

        $this->getJson('/api/students/directory')
            ->assertForbidden();
    }

    private function makeUser(string $email, string $role, ?string $studentId = null, ?int $yearLevel = null): User
    {
        return User::factory()->create([
            'name' => ucfirst(strtok($email, '@')) . ' User',
            'email' => $email,
            'role' => $role,
            'student_id' => $studentId,
            'year_level' => $role === User::ROLE_STUDENT ? $yearLevel : null,
            'is_active' => true,
        ]);
    }

    private function makeRoom(User $staff, string $name, string $code): Room
    {
        return Room::create([
            'name' => $name,
            'code' => $code,
            'created_by' => $staff->id,
        ]);
    }

    private function makeExam(User $staff, string $title, ?string $subject = null): Exam
    {
        return Exam::create([
            'title' => $title,
            'subject' => $subject,
            'description' => null,
            'total_items' => 50,
            'duration_minutes' => 60,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);
    }

    private function assignExamToRoom(Exam $exam, Room $room, User $staff, $archivedAt = null): void
    {
        $exam->rooms()->attach($room->id, [
            'assigned_by' => $staff->id,
            'archived_at' => $archivedAt,
            'archived_by' => $archivedAt ? $staff->id : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
