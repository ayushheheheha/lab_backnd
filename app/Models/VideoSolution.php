<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSolution extends Model
{
    public const PROVIDER_GOOGLE_DRIVE = 'google_drive';
    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDERS = [
        self::PROVIDER_GOOGLE_DRIVE,
        self::PROVIDER_YOUTUBE,
    ];

    protected $fillable = [
        'course_id',
        'title',
        'provider',
        'drive_file_id',
        'youtube_id',
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

    // NOTE: embed_url is intentionally NOT in $appends. The playable reference
    // (Drive preview / YouTube embed) is never serialized into list responses —
    // it is only resolved through the gated, short-lived play endpoint so the
    // underlying video id is kept out of the public API surface.

    /**
     * Build the underlying embed URL for this video. Server-side only — this is
     * rendered inside the hardened embed page, never returned as JSON.
     */
    public function buildEmbedUrl(): ?string
    {
        if ($this->provider === self::PROVIDER_YOUTUBE) {
            if (! $this->youtube_id) {
                return null;
            }

            // youtube-nocookie + minimal branding. controls=0 because the embed
            // page draws its own controls over a click-blocking overlay.
            $params = http_build_query([
                'rel'            => 0,
                'modestbranding' => 1,
                'controls'       => 0,
                'disablekb'      => 1,
                'iv_load_policy' => 3,
                'playsinline'    => 1,
                'fs'             => 1,
                'enablejsapi'    => 1,
            ]);

            return "https://www.youtube-nocookie.com/embed/{$this->youtube_id}?{$params}";
        }

        if (! $this->drive_file_id) {
            return null;
        }

        return "https://drive.google.com/file/d/{$this->drive_file_id}/preview";
    }

    /**
     * Whether the given user is allowed to play this video. Free videos are open
     * to everyone; Pro videos require an admin or a Pro subscriber.
     */
    public function isAccessibleBy(?User $user): bool
    {
        if (! $this->is_pro) {
            return true;
        }

        return $user !== null && ($user->is_admin || $user->is_pro);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
