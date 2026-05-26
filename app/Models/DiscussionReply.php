<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DiscussionReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'discussion_id', 'user_id', 'body',
        'is_anonymous', 'is_endorsed', 'vote_count',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'is_endorsed'  => 'boolean',
        'vote_count'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    public function votes(): MorphMany
    {
        return $this->morphMany(DiscussionVote::class, 'votable');
    }
}
