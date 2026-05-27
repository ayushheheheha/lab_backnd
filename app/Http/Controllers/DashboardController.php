<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Course;
use App\Models\IDESubmission;
use App\Models\Quiz;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $now  = now();

        return response()->json([
            'greeting'         => $this->greeting($now),
            'streak'           => $this->streak($user->id, $now),
            'accuracy'         => $this->accuracyWidget($user->id, $now),
            'hours_this_week'  => $this->hoursWidget($user->id, $now),
            'rank'             => $this->rankWidget($user->id, $now),
            'performance'      => $this->performance($user->id, $now),
            'todays_challenge' => $this->todaysChallenge($user->id),
            'active_now'       => $this->activeNow($now),
        ]);
    }

    private function greeting(Carbon $now): array
    {
        $h = (int) $now->hour;
        $period = match (true) {
            $h < 12 => 'morning',
            $h < 17 => 'afternoon',
            $h < 21 => 'evening',
            default => 'night',
        };

        return [
            'time_of_day' => $period,
            'weekday'     => strtolower($now->englishDayOfWeek),
            'date'        => $now->format('M j, Y'),
        ];
    }

    private function activityDates(int $userId, Carbon $since): \Illuminate\Support\Collection
    {
        $quizDates = Attempt::query()
            ->where('user_id', $userId)
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $since)
            ->pluck('submitted_at')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'));

        $ideDates = IDESubmission::query()
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $since)
            ->pluck('submitted_at')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'));

        return $quizDates->merge($ideDates)->unique();
    }

    private function streak(int $userId, Carbon $now): array
    {
        $since = $now->copy()->subDays(60)->startOfDay();
        $dates = $this->activityDates($userId, $since);

        $streak = 0;
        $cursor = $now->copy()->startOfDay();
        while ($dates->contains($cursor->format('Y-m-d'))) {
            $streak++;
            $cursor->subDay();
        }

        // No activity today yet — still count yesterday onward
        if ($streak === 0) {
            $cursor = $now->copy()->subDay()->startOfDay();
            while ($dates->contains($cursor->format('Y-m-d'))) {
                $streak++;
                $cursor->subDay();
            }
        }

        // 28-day history (oldest first)
        $history = [];
        for ($i = 27; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i)->format('Y-m-d');
            $history[] = $dates->contains($day) ? 1 : 0;
        }

        return [
            'days'    => $streak,
            'history' => $history,
        ];
    }

    private function weeklyAttempts(int $userId, Carbon $oldestStart): \Illuminate\Support\Collection
    {
        return Attempt::query()
            ->where('user_id', $userId)
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $oldestStart)
            ->whereNotNull('total_marks')
            ->where('total_marks', '>', 0)
            ->get(['score', 'total_marks', 'started_at', 'submitted_at']);
    }

    private function weekWindows(Carbon $now, int $weeks = 12): array
    {
        $windows = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek();
            $end   = $start->copy()->endOfWeek();
            $windows[] = ['label' => $start->format('M j'), 'start' => $start, 'end' => $end];
        }
        return $windows;
    }

    private function accuracyWidget(int $userId, Carbon $now): array
    {
        $windows  = $this->weekWindows($now);
        $oldest   = $windows[0]['start'];
        $attempts = $this->weeklyAttempts($userId, $oldest);

        $history = [];
        foreach ($windows as $w) {
            $weekAtt = $attempts->filter(fn ($a) => $a->submitted_at >= $w['start'] && $a->submitted_at <= $w['end']);

            if ($weekAtt->isEmpty()) {
                $history[] = 0;
                continue;
            }
            $history[] = round($weekAtt->avg(fn ($a) => ($a->score / max(0.01, $a->total_marks)) * 100), 1);
        }

        $current = end($history);
        $prev    = count($history) >= 2 ? $history[count($history) - 2] : 0;
        $delta   = round($current - $prev, 1);

        return [
            'value'   => (float) $current,
            'delta'   => $delta,
            'history' => $history,
        ];
    }

    private function hoursWidget(int $userId, Carbon $now): array
    {
        $windows  = $this->weekWindows($now);
        $oldest   = $windows[0]['start'];
        $attempts = $this->weeklyAttempts($userId, $oldest);

        $history = [];
        foreach ($windows as $w) {
            $weekAtt = $attempts->filter(fn ($a) => $a->submitted_at >= $w['start'] && $a->submitted_at <= $w['end']);
            $seconds = $weekAtt->reduce(function ($carry, $a) {
                if (! $a->started_at || ! $a->submitted_at) return $carry;
                return $carry + max(0, $a->submitted_at->diffInSeconds($a->started_at));
            }, 0);
            $history[] = round($seconds / 3600, 2);
        }

        $current = end($history);
        $prev    = count($history) >= 2 ? $history[count($history) - 2] : 0;

        return [
            'value'   => round($current, 1),
            'delta'   => round($current - $prev, 1),
            'history' => $history,
        ];
    }

    private function rankWidget(int $userId, Carbon $now): array
    {
        $compute = function ($cutoff) {
            $query = DB::table('attempts')
                ->where('is_complete', true)
                ->whereNotNull('submitted_at')
                ->whereNotNull('total_marks')
                ->where('total_marks', '>', 0);

            if ($cutoff) {
                $query->where('submitted_at', '<', $cutoff);
            }

            return $query
                ->select(
                    'user_id',
                    DB::raw('AVG((score / total_marks) * 100) as avg_score'),
                    DB::raw('COUNT(*) as cnt')
                )
                ->groupBy('user_id')
                ->orderByDesc('avg_score')
                ->orderByDesc('cnt')
                ->get();
        };

        $current  = $compute(null);
        $previous = $compute($now->copy()->subDays(7));

        $rankOf = function ($rows, $uid) {
            foreach ($rows as $i => $row) {
                if ((int) $row->user_id === (int) $uid) {
                    return $i + 1;
                }
            }
            return null;
        };

        $currentRank  = $rankOf($current, $userId);
        $previousRank = $rankOf($previous, $userId);

        $movedUp = null;
        if ($currentRank !== null && $previousRank !== null) {
            $movedUp = $previousRank - $currentRank;
        }

        return [
            'current'   => $currentRank,
            'previous'  => $previousRank,
            'moved_up'  => $movedUp,
            'total_ranked' => $current->count(),
        ];
    }

    private function performance(int $userId, Carbon $now): array
    {
        $windows  = $this->weekWindows($now);
        $oldest   = $windows[0]['start'];
        $attempts = $this->weeklyAttempts($userId, $oldest);

        return collect($windows)->map(function ($w) use ($attempts) {
            $weekAtt = $attempts->filter(fn ($a) => $a->submitted_at >= $w['start'] && $a->submitted_at <= $w['end']);

            if ($weekAtt->isEmpty()) {
                return [
                    'label'    => $w['label'],
                    'accuracy' => 0,
                    'speed'    => 0,
                    'score'    => 0,
                    'attempts' => 0,
                ];
            }

            $accuracy   = $weekAtt->avg(fn ($a) => ($a->score / max(0.01, $a->total_marks)) * 100);
            $totalScore = $weekAtt->sum('score');
            $totalMax   = $weekAtt->sum('total_marks');

            $avgSeconds = $weekAtt->avg(function ($a) {
                if (! $a->started_at || ! $a->submitted_at) return 0;
                return max(0, $a->submitted_at->diffInSeconds($a->started_at));
            });

            // Speed: 60s → 100, 600s → 0 (linear)
            $speed = $avgSeconds > 0
                ? max(0, min(100, 100 - (($avgSeconds - 60) / 5.4)))
                : 0;

            return [
                'label'    => $w['label'],
                'accuracy' => round($accuracy, 1),
                'speed'    => round($speed, 1),
                'score'    => round(($totalScore / max(0.01, $totalMax)) * 100, 1),
                'attempts' => $weekAtt->count(),
            ];
        })->values()->all();
    }

    private function todaysChallenge(int $userId): ?array
    {
        $attemptedQuizIds = Attempt::query()
            ->where('user_id', $userId)
            ->where('is_complete', true)
            ->pluck('quiz_id')
            ->unique();

        $courses = Course::query()
            ->where('is_active', true)
            ->with([
                'quizzes' => fn ($q) => $q
                    ->where('is_active', true)
                    ->where('section', 'practice')
                    ->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->get();

        // In-progress course → first un-attempted practice quiz
        foreach ($courses as $course) {
            $quizIds   = $course->quizzes->pluck('id');
            $attempted = $quizIds->intersect($attemptedQuizIds)->count();
            if ($attempted > 0 && $attempted < $quizIds->count()) {
                $next = $course->quizzes->first(fn ($q) => ! $attemptedQuizIds->contains($q->id));
                if ($next) return $this->challengePayload($next, $course);
            }
        }

        // Fallback: any unattempted quiz from any course with quizzes
        foreach ($courses as $course) {
            if ($course->quizzes->isEmpty()) continue;
            $next = $course->quizzes->first(fn ($q) => ! $attemptedQuizIds->contains($q->id));
            if ($next) return $this->challengePayload($next, $course);
        }

        // Final fallback: very first quiz
        $first = Quiz::query()
            ->where('is_active', true)
            ->where('section', 'practice')
            ->with('course:id,name,slug')
            ->orderBy('id')
            ->first();

        return $first && $first->course ? $this->challengePayload($first, $first->course) : null;
    }

    private function challengePayload(Quiz $quiz, Course $course): array
    {
        return [
            'quiz_id'            => $quiz->id,
            'title'              => $quiz->title,
            'course_name'        => $course->name,
            'course_slug'        => $course->slug,
            'icon'               => $course->icon,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'xp'                 => 200,
        ];
    }

    private function activeNow(Carbon $now): int
    {
        $since = $now->copy()->subMinutes(15);

        $quizUsers = Attempt::query()
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $since)
            ->distinct()
            ->pluck('user_id');

        $ideUsers = IDESubmission::query()
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $since)
            ->distinct()
            ->pluck('user_id');

        return $quizUsers->merge($ideUsers)->unique()->count();
    }
}
