<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF_MASTER_EXAMINER = 'staff_master_examiner';
    public const ROLE_STUDENT = 'student';
    public const YEAR_LEVELS = [1, 2, 3, 4];

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_STAFF_MASTER_EXAMINER,
        self::ROLE_STUDENT,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'student_id',
        'role',
        'year_level',
        'is_active',
        'archived_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Rooms created by this user.
     */
    public function createdRooms(): HasMany
    {
        return $this->hasMany(Room::class, 'created_by');
    }

    /**
     * Exams created by this user.
     */
    public function createdExams(): HasMany
    {
        return $this->hasMany(Exam::class, 'created_by');
    }

    /**
     * Question banks created by this user.
     */
    public function createdQuestionBanks(): HasMany
    {
        return $this->hasMany(QuestionBank::class, 'created_by');
    }

    /**
     * Audit logs performed by this user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    /**
     * Rooms where this user is a member.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)->withTimestamps();
    }

    /**
     * Limit the query to student accounts.
     */
    public function scopeStudents(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_STUDENT);
    }

    /**
     * Limit the query to current student accounts.
     */
    public function scopeCurrentStudents(Builder $query): Builder
    {
        return $query->students()->whereNull('archived_at');
    }

    /**
     * Limit the query to archived student accounts.
     */
    public function scopeArchivedStudents(Builder $query): Builder
    {
        return $query->students()->whereNotNull('archived_at');
    }

    /**
     * Exam attempts taken by this user.
     */
    public function examAttempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class, 'user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'year_level' => 'integer',
            'archived_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
