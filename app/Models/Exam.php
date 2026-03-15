<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Rooms where this exam is assigned.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'exam_room')
            ->withPivot(['assigned_by'])
            ->withTimestamps();
    }

    /**
     * Student attempts for this exam.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }
}
