<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IDESubmission extends Model
{
    use HasFactory;

    protected $table = 'ide_submissions';

    protected $fillable = [
        'ide_problem_id',
        'user_id',
        'language',
        'code',
        'status',
        'test_results',
        'submitted_at',
    ];

    protected $casts = [
        'test_results' => 'array',
    ];

    public function ideProblem(): BelongsTo
    {
        return $this->belongsTo(IDEProblem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
