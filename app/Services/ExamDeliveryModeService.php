<?php

namespace App\Services;

use App\Models\Exam;
use Illuminate\Support\Facades\DB;

class ExamDeliveryModeService
{
    public function normalize(?string $mode): string
    {
        return match ((string) $mode) {
            Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            Exam::DELIVERY_MODE_TEACHER_PACED,
            Exam::DELIVERY_MODE_INSTANT_FEEDBACK => (string) $mode,
            Exam::DELIVERY_MODE_LIVE_QUIZ => Exam::DELIVERY_MODE_TEACHER_PACED,
            default => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
        };
    }

    /**
     * @return array<int, string>
     */
    public function validationModes(): array
    {
        return [
            ...Exam::DELIVERY_MODES,
            Exam::DELIVERY_MODE_STANDARD,
            Exam::DELIVERY_MODE_LIVE_QUIZ,
        ];
    }

    public function resolveTeacherPacedTotalItems(Exam $exam): int
    {
        $configuredItems = max(1, (int) $exam->total_items);

        if (!$exam->question_bank_id) {
            return $configuredItems;
        }

        $availableItems = DB::table('question_bank_questions')
            ->where('question_bank_id', (int) $exam->question_bank_id)
            ->count();

        if ($availableItems < 1) {
            return $configuredItems;
        }

        return max(1, min($configuredItems, (int) $availableItems));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveTeacherPacingState(int $examId, int $roomId): ?array
    {
        $state = DB::table('exam_room_pacing_states')
            ->select('is_active', 'current_item_number', 'started_at', 'updated_at')
            ->where('exam_id', $examId)
            ->where('room_id', $roomId)
            ->first();

        if (!$state) {
            return null;
        }

        return [
            'is_active' => (bool) $state->is_active,
            'current_item_number' => is_null($state->current_item_number) ? null : (int) $state->current_item_number,
            'started_at' => $state->started_at,
            'updated_at' => $state->updated_at,
        ];
    }

    /**
     * @param array<string, mixed>|null $state
     * @return array<string, mixed>|null
     */
    public function buildTeacherPacingPayload(?array $state, int $totalItems): ?array
    {
        if (is_null($state)) {
            return null;
        }

        $currentItemNumber = is_null($state['current_item_number'] ?? null)
            ? null
            : max(1, min((int) $state['current_item_number'], max(1, $totalItems)));

        return [
            'is_active' => (bool) ($state['is_active'] ?? false),
            'current_item_number' => $currentItemNumber,
            'total_items' => max(1, $totalItems),
            'started_at' => $state['started_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }
}

