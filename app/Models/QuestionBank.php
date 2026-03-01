<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBank extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'subject',
        'source_filename',
        'total_items',
        'created_by',
    ];

    /**
     * User who created this bank.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Questions under this bank.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class)->orderBy('item_number');
    }

    /**
     * Exams that use this bank.
     */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'question_bank_id');
    }
}
