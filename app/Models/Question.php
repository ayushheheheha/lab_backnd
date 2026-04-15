<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'quiz_id',
        'type',
        'stem',
        'stem_image',
        'stem_code',
        'stem_code_language',
        'explanation',
        'marks',
        'difficulty',
        'position',
        'numerical_answer',
        'numerical_tolerance',
    ];

    protected $appends = [
        'options',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function questionOptions(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function shortAnswerAcceptables(): HasMany
    {
        return $this->hasMany(ShortAnswerAcceptable::class);
    }

    public function getOptionsAttribute()
    {
        return $this->questionOptions()->get();
    }
}
