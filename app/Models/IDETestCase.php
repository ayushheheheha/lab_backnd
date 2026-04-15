<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IDETestCase extends Model
{
    use HasFactory;

    protected $table = 'ide_test_cases';

    protected $fillable = [
        'ide_problem_id',
        'input',
        'expected_output',
        'is_hidden',
        'position',
    ];

    public function ideProblem(): BelongsTo
    {
        return $this->belongsTo(IDEProblem::class, 'ide_problem_id', 'id');
    }
}
