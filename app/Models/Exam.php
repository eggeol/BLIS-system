<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Exam extends Model
{
    use HasFactory;

    public const DELIVERY_MODE_OPEN_NAVIGATION = 'open_navigation';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'subject',
        'description',
        'question_bank_id',
        'total_items',
        'duration_minutes',
        'scheduled_at',
        'schedule_start_at',
        'schedule_end_at',
        'delivery_mode',
        'one_take_only',
        'shuffle_questions',
        'results_visibility_mode',
        'created_by',
    ];

    /**
     * Attribute casting.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'schedule_start_at' => 'datetime',
        'schedule_end_at' => 'datetime',
        'one_take_only' => 'boolean',
        'shuffle_questions' => 'boolean',
    ];

    /**
     * Exam creator (staff/admin).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Source question bank for this exam.
     */
    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    /**
     * Question banks linked to this exam in the selected order.
     */
    public function questionBanks(): BelongsToMany
    {
        return $this->belongsToMany(QuestionBank::class, 'exam_question_bank')
            ->withPivot(['position'])
            ->withTimestamps()
            ->orderBy('exam_question_bank.position')
            ->orderBy('question_banks.id');
    }

    /**
     * Rooms where this exam is assigned.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'exam_room')
            ->withPivot(['assigned_by', 'archived_at', 'archived_by'])
            ->withTimestamps();
    }

    /**
     * Rooms where this exam is currently active.
     */
    public function activeRooms(): BelongsToMany
    {
        return $this->rooms()
            ->wherePivotNull('archived_at');
    }

    /**
     * Rooms where this exam is archived.
     */
    public function archivedRooms(): BelongsToMany
    {
        return $this->rooms()
            ->wherePivotNotNull('archived_at')
            ->withTimestamps();
    }

    /**
     * Student attempts for this exam.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /**
     * Resolve the configured question banks, falling back to the legacy single-bank link.
     *
     * @return Collection<int, QuestionBank>
     */
    public function resolvedQuestionBanks(): Collection
    {
        if ($this->relationLoaded('questionBanks') && $this->questionBanks->isNotEmpty()) {
            return $this->questionBanks->values();
        }

        $linkedBanks = $this->questionBanks()->get();
        if ($linkedBanks->isNotEmpty()) {
            return $linkedBanks->values();
        }

        if (!$this->question_bank_id) {
            return collect();
        }

        $legacyBank = $this->relationLoaded('questionBank')
            ? $this->questionBank
            : $this->questionBank()->first();

        return $legacyBank ? collect([$legacyBank]) : collect();
    }

    /**
     * @return list<int>
     */
    public function resolvedQuestionBankIds(): array
    {
        return $this->resolvedQuestionBanks()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Persist linked question banks in the given order.
     *
     * @param  list<int>  $questionBankIds
     */
    public function syncQuestionBanks(array $questionBankIds): void
    {
        $normalizedIds = collect($questionBankIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $now = now();
        $syncPayload = $normalizedIds
            ->mapWithKeys(fn ($bankId, $index) => [$bankId => [
                'position' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]])
            ->all();

        $this->questionBanks()->sync($syncPayload);
    }

    /**
     * Summarize the exam subject from its linked question banks.
     *
     * @param  iterable<QuestionBank>  $questionBanks
     */
    public static function summarizeQuestionBankSubject(iterable $questionBanks): ?string
    {
        $subjects = collect($questionBanks)
            ->map(fn (QuestionBank $bank) => trim((string) ($bank->subject ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($subjects->isEmpty()) {
            return null;
        }

        if ($subjects->count() === 1) {
            return (string) $subjects->first();
        }

        return 'Multiple Subjects';
    }
}
