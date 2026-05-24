<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSolution extends Model
{
    protected $fillable = [
        'course_id',
        'title',
        'drive_file_id',
        'description',
        'author',
        'duration',
        'thumbnail_url',
        'chapters',
        'is_pro',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'chapters'     => 'array',
        'is_pro'       => 'boolean',
        'is_published' => 'boolean',
    ];

    protected $appends = ['embed_url'];

    public function getEmbedUrlAttribute(): string
    {
        return "https://drive.google.com/file/d/{$this->drive_file_id}/preview";
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
