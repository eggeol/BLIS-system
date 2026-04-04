<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'created_by',
    ];

    /**
     * Room creator (staff/admin).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Users enrolled in this room.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Current students enrolled in this room.
     */
    public function currentStudentMembers(): BelongsToMany
    {
        return $this->members()
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at');
    }

    /**
     * Archived students retained for historical records.
     */
    public function archivedStudentMembers(): BelongsToMany
    {
        return $this->members()
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNotNull('users.archived_at');
    }

    /**
     * Exams assigned to this room.
     */
    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'exam_room')
            ->withPivot(['assigned_by', 'archived_at', 'archived_by'])
            ->withTimestamps();
    }

    /**
     * Active exams assigned to this room.
     */
    public function activeExams(): BelongsToMany
    {
        return $this->exams()
            ->wherePivotNull('archived_at');
    }

    /**
     * Archived exam assignments retained for history.
     */
    public function archivedExams(): BelongsToMany
    {
        return $this->exams()
            ->wherePivotNotNull('archived_at')
            ->withTimestamps();
    }

    /**
     * Exam attempts taken in this room.
     */
    public function examAttempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }
}
