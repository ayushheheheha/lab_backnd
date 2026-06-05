<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    use HasFactory;

    public const SECTIONS = ['practice', 'practice_graded', 'quiz1', 'quiz2', 'endterm', 'mock_test'];
    public const WEEKLY_SECTIONS = ['practice', 'practice_graded'];

    protected $fillable = [
        'course_id',
        'week_id',
        'section',
        'year',
        'title',
        'description',
        'time_limit_minutes',
        'is_active',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }
}
