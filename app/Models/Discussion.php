<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Discussion extends Model
{
    use HasFactory;

    public const SUBJECTS = [
        'general'     => 'General',
        'programming' => 'Programming',
        'mathematics' => 'Mathematics',
        'data-science'=> 'Data Science',
        'career'      => 'Career',
        'study-tips'  => 'Study Tips',
        'off-topic'   => 'Off-Topic',
    ];

    protected $fillable = [
        'user_id', 'course_id', 'subject', 'title', 'body',
        'is_anonymous', 'linked_quiz_id', 'accepted_reply_id',
        'is_solved', 'vote_count', 'reply_count', 'view_count',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'is_solved'    => 'boolean',
        'vote_count'   => 'integer',
        'reply_count'  => 'integer',
        'view_count'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function linkedQuiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'linked_quiz_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(DiscussionReply::class)->orderBy('created_at');
    }

    public function votes(): MorphMany
    {
        return $this->morphMany(DiscussionVote::class, 'votable');
    }
}
