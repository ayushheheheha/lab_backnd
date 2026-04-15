<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IDEProblem extends Model
{
    use HasFactory;

    protected $table = 'ide_problems';

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'constraints',
        'input_format',
        'output_format',
        'sample_input',
        'sample_output',
        'difficulty',
        'week_number',
        'language',
        'time_limit_seconds',
        'memory_limit_mb',
        'is_active',
        'sort_order',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function testCases(): HasMany
    {
        return $this->hasMany(IDETestCase::class)->orderBy('position');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(IDESubmission::class);
    }
}
