<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class XpService
{
    public const MAX_LEVEL = 50;
    public const LEVEL_COEFFICIENT = 50; // XP to reach level N = COEFFICIENT * N^2 (L1 special-cased to 0)

    // XP award amounts
    public const XP_ON_ACCEPTED_REPLY = 50;
    public const XP_PER_UPVOTE_RECEIVED = 2;
    public const XP_QUIZ_BASE = 5;
    public const XP_QUIZ_REATTEMPT_FACTOR = 0.5;
    public const XP_IDE_EASY = 20;
    public const XP_IDE_MEDIUM = 40;
    public const XP_IDE_HARD = 80;
    public const XP_DAILY_BONUS = 10;

    // Badge thresholds — { level => [slug, label, description] }
    public const BADGES = [
        1  => ['newcomer',    'Newcomer',     'Joined the platform'],
        5  => ['apprentice',  'Apprentice',   'Reached Level 5'],
        10 => ['scholar',     'Scholar',      'Reached Level 10'],
        15 => ['adept',       'Adept',        'Reached Level 15'],
        20 => ['achiever',    'Achiever',     'Reached Level 20'],
        25 => ['veteran',     'Veteran',      'Reached Level 25'],
        30 => ['expert',      'Expert',       'Reached Level 30'],
        35 => ['master',      'Master',       'Reached Level 35'],
        40 => ['grandmaster', 'Grandmaster',  'Reached Level 40'],
        45 => ['legend',      'Legend',       'Reached Level 45'],
        50 => ['iit-champion','IIT Champion', 'Maxed out — Level 50'],
    ];

    /**
     * XP needed to *reach* the start of a given level. Level 1 starts at 0 XP.
     */
    public static function xpForLevel(int $level): int
    {
        $level = max(1, min(self::MAX_LEVEL, $level));
        if ($level === 1) {
            return 0;
        }
        return self::LEVEL_COEFFICIENT * $level * $level;
    }

    /**
     * Compute current level (1-50) from total XP.
     */
    public static function levelFromXp(int $xp): int
    {
        if ($xp <= 0) {
            return 1;
        }
        for ($lvl = self::MAX_LEVEL; $lvl >= 1; $lvl--) {
            if ($xp >= self::xpForLevel($lvl)) {
                return $lvl;
            }
        }
        return 1;
    }

    /**
     * Build a progress snapshot for the UI.
     */
    public static function progress(int $xp): array
    {
        $xp = max(0, (int) $xp);
        $level = self::levelFromXp($xp);
        $isMaxed = $level >= self::MAX_LEVEL;

        $thisLevelStart = self::xpForLevel($level);
        $nextLevelStart = $isMaxed ? $thisLevelStart : self::xpForLevel($level + 1);
        $span = max(1, $nextLevelStart - $thisLevelStart);
        $into = $xp - $thisLevelStart;

        return [
            'xp' => $xp,
            'level' => $level,
            'is_max' => $isMaxed,
            'xp_into_level' => $into,
            'xp_for_level' => $span,
            'xp_to_next' => $isMaxed ? 0 : max(0, $nextLevelStart - $xp),
            'progress_percent' => $isMaxed ? 100 : min(100, (int) round(($into / $span) * 100)),
            'next_level_xp_total' => $nextLevelStart,
            'this_level_xp_total' => $thisLevelStart,
        ];
    }

    /**
     * Badges earned at or below the given level.
     */
    public static function badgesForLevel(int $level): array
    {
        $out = [];
        foreach (self::BADGES as $threshold => [$slug, $label, $description]) {
            if ($level >= $threshold) {
                $out[] = [
                    'slug' => $slug,
                    'label' => $label,
                    'description' => $description,
                    'level' => $threshold,
                ];
            }
        }
        return $out;
    }

    /**
     * Full badge catalog with earned status, for the gallery view.
     */
    public static function badgeCatalog(int $level): array
    {
        $out = [];
        foreach (self::BADGES as $threshold => [$slug, $label, $description]) {
            $out[] = [
                'slug' => $slug,
                'label' => $label,
                'description' => $description,
                'level' => $threshold,
                'earned' => $level >= $threshold,
            ];
        }
        return $out;
    }

    /**
     * Award XP to a user, capped so they cannot exceed the L50 threshold.
     * Returns an envelope describing the gain and any badges/levels unlocked.
     *
     * @return array{xp_before:int,xp_after:int,xp_gained:int,level_before:int,level_after:int,leveled_up:bool,new_badges:array,reason:string}
     */
    public function award(User $user, int $rawAmount, string $reason): array
    {
        $rawAmount = (int) $rawAmount;
        if ($rawAmount === 0) {
            return $this->noopEnvelope($user, $reason);
        }

        $maxXp = self::xpForLevel(self::MAX_LEVEL);

        return DB::transaction(function () use ($user, $rawAmount, $reason, $maxXp) {
            $fresh = User::query()->lockForUpdate()->find($user->id);
            $before = (int) $fresh->xp;
            $levelBefore = self::levelFromXp($before);

            $after = $rawAmount > 0
                ? min($maxXp, $before + $rawAmount)
                : max(0, $before + $rawAmount);

            $actualGain = $after - $before;
            if ($actualGain === 0) {
                return $this->noopEnvelope($fresh, $reason);
            }

            $levelAfter = self::levelFromXp($after);
            $newBadges = [];

            if ($levelAfter > $levelBefore) {
                foreach (self::BADGES as $threshold => [$slug, $label, $description]) {
                    if ($threshold > $levelBefore && $threshold <= $levelAfter) {
                        $newBadges[] = [
                            'slug' => $slug,
                            'label' => $label,
                            'description' => $description,
                            'level' => $threshold,
                        ];
                    }
                }
            }

            $fresh->xp = $after;
            if ($levelAfter > (int) $fresh->highest_level_reached) {
                $fresh->highest_level_reached = $levelAfter;
            }
            $fresh->save();

            // Refresh the passed instance for callers
            $user->xp = $after;
            $user->highest_level_reached = $fresh->highest_level_reached;

            return [
                'xp_before' => $before,
                'xp_after' => $after,
                'xp_gained' => $actualGain,
                'level_before' => $levelBefore,
                'level_after' => $levelAfter,
                'leveled_up' => $levelAfter > $levelBefore,
                'new_badges' => $newBadges,
                'reason' => $reason,
            ];
        });
    }

    /**
     * Award the once-per-day bonus if the user hasn't received one today.
     * Returns the award envelope (may be null-equivalent if no-op).
     */
    public function applyDailyBonusIfFirstToday(User $user): array
    {
        $today = now()->toDateString();
        $last = $user->last_xp_action_date;
        $lastStr = $last instanceof \DateTimeInterface ? $last->format('Y-m-d') : (string) $last;

        if ($lastStr === $today) {
            return $this->noopEnvelope($user, 'daily_bonus');
        }

        $envelope = $this->award($user, self::XP_DAILY_BONUS, 'daily_bonus');

        User::where('id', $user->id)->update(['last_xp_action_date' => $today]);
        $user->last_xp_action_date = $today;

        return $envelope;
    }

    /**
     * Combine two award envelopes (e.g. daily bonus + quiz reward) for a single API response.
     */
    public function combine(array $a, array $b): array
    {
        if (($a['xp_gained'] ?? 0) === 0) {
            return $b;
        }
        if (($b['xp_gained'] ?? 0) === 0) {
            return $a;
        }
        return [
            'xp_before' => $a['xp_before'],
            'xp_after' => $b['xp_after'],
            'xp_gained' => ($a['xp_gained'] ?? 0) + ($b['xp_gained'] ?? 0),
            'level_before' => $a['level_before'],
            'level_after' => $b['level_after'],
            'leveled_up' => ($a['leveled_up'] ?? false) || ($b['leveled_up'] ?? false),
            'new_badges' => array_merge($a['new_badges'] ?? [], $b['new_badges'] ?? []),
            'reason' => 'combined',
        ];
    }

    private function noopEnvelope(User $user, string $reason): array
    {
        $xp = (int) $user->xp;
        $level = self::levelFromXp($xp);
        return [
            'xp_before' => $xp,
            'xp_after' => $xp,
            'xp_gained' => 0,
            'level_before' => $level,
            'level_after' => $level,
            'leveled_up' => false,
            'new_badges' => [],
            'reason' => $reason,
        ];
    }
}
