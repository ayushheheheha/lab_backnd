<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Course;
use App\Models\LoginLog;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $recentAttempts = Attempt::query()
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->with([
                'user:id,name',
                'quiz:id,course_id,title',
                'quiz.course:id,name',
            ])
            ->orderByDesc('submitted_at')
            ->limit(10)
            ->get()
            ->map(fn (Attempt $attempt) => [
                'student_name' => $attempt->user?->name,
                'quiz_title'   => $attempt->quiz?->title,
                'course_name'  => $attempt->quiz?->course?->name,
                'score'        => (float) ($attempt->score ?? 0),
                'total_marks'  => (float) ($attempt->total_marks ?? 0),
                'submitted_at' => $attempt->submitted_at,
            ])
            ->values();

        $recentLogins = LoginLog::query()
            ->with('user:id,name,email,is_admin,is_pro')
            ->orderByDesc('logged_in_at')
            ->limit(20)
            ->get()
            ->map(fn (LoginLog $log) => [
                'id'          => $log->id,
                'user_id'     => $log->user_id,
                'name'        => $log->user?->name ?? '—',
                'email'       => $log->user?->email ?? '—',
                'is_admin'    => (bool) ($log->user?->is_admin ?? false),
                'is_pro'      => (bool) ($log->user?->is_pro ?? false),
                'ip_address'  => $log->ip_address,
                'auth_method' => $log->auth_method,
                'logged_in_at' => $log->logged_in_at,
            ])
            ->values();

        return response()->json([
            'total_students'       => User::query()->where('is_admin', false)->count(),
            'total_quizzes'        => Quiz::query()->count(),
            'total_questions'      => Question::query()->count(),
            'total_courses'        => Course::query()->count(),
            'total_attempts_today' => Attempt::query()
                ->whereDate('submitted_at', today())
                ->where('is_complete', true)
                ->count(),
            'recent_attempts' => $recentAttempts,
            'recent_logins'   => $recentLogins,
        ]);
    }

    public function analytics(): JsonResponse
    {
        // ── Who is online right now (active in the last 5 minutes) ──
        $onlineThreshold = now()->subMinutes(5);

        $online = User::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $onlineThreshold)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get(['id', 'name', 'email', 'is_admin', 'is_pro', 'last_seen_at'])
            ->map(fn (User $u) => [
                'id'           => $u->id,
                'name'         => $u->name,
                'email'        => $u->email,
                'is_admin'     => (bool) $u->is_admin,
                'is_pro'       => (bool) $u->is_pro,
                'last_seen_at' => $u->last_seen_at,
            ])
            ->values();

        // ── 14-day time series: signups & completed attempts per day ──
        $days = 14;
        $start = now()->startOfDay()->subDays($days - 1);

        $signupRows = User::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $attemptRows = Attempt::query()
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $start)
            ->selectRaw('DATE(submitted_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $signupsDaily = [];
        $attemptsDaily = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->toDateString();
            $label = $day->format('d M');
            $signupsDaily[] = ['date' => $label, 'count' => (int) ($signupRows[$key] ?? 0)];
            $attemptsDaily[] = ['date' => $label, 'count' => (int) ($attemptRows[$key] ?? 0)];
        }

        // ── Score distribution across all completed attempts ──
        $dist = Attempt::query()
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->where('total_marks', '>', 0)
            ->selectRaw('
                SUM(CASE WHEN score/total_marks < 0.4 THEN 1 ELSE 0 END) as b1,
                SUM(CASE WHEN score/total_marks >= 0.4 AND score/total_marks < 0.6 THEN 1 ELSE 0 END) as b2,
                SUM(CASE WHEN score/total_marks >= 0.6 AND score/total_marks < 0.75 THEN 1 ELSE 0 END) as b3,
                SUM(CASE WHEN score/total_marks >= 0.75 AND score/total_marks < 0.9 THEN 1 ELSE 0 END) as b4,
                SUM(CASE WHEN score/total_marks >= 0.9 THEN 1 ELSE 0 END) as b5
            ')
            ->first();

        $scoreDistribution = [
            ['range' => '0–40%',   'count' => (int) ($dist->b1 ?? 0)],
            ['range' => '40–60%',  'count' => (int) ($dist->b2 ?? 0)],
            ['range' => '60–75%',  'count' => (int) ($dist->b3 ?? 0)],
            ['range' => '75–90%',  'count' => (int) ($dist->b4 ?? 0)],
            ['range' => '90–100%', 'count' => (int) ($dist->b5 ?? 0)],
        ];

        // ── Attempts per course (with the course's program level for filtering) ──
        $attemptsByCourse = Attempt::query()
            ->where('attempts.is_complete', true)
            ->join('quizzes', 'attempts.quiz_id', '=', 'quizzes.id')
            ->join('courses', 'quizzes.course_id', '=', 'courses.id')
            ->selectRaw('courses.name as course, courses.level as level, COUNT(*) as c')
            ->groupBy('courses.name', 'courses.level')
            ->orderByDesc('c')
            ->limit(12)
            ->get()
            ->map(fn ($r) => [
                'course' => $r->course,
                'level'  => $r->level,
                'count'  => (int) $r->c,
            ])
            ->values();

        return response()->json([
            'online' => [
                'students' => $online->where('is_admin', false)->count(),
                'total'    => $online->count(),
                'users'    => $online,
            ],
            'totals' => [
                'total_students' => User::query()->where('is_admin', false)->count(),
                'active_today'   => User::query()->whereDate('last_seen_at', today())->count(),
                'new_this_week'  => User::query()->where('created_at', '>=', now()->startOfWeek())->count(),
                'attempts_total' => Attempt::query()->where('is_complete', true)->count(),
            ],
            'signups_daily'      => $signupsDaily,
            'attempts_daily'     => $attemptsDaily,
            'score_distribution' => $scoreDistribution,
            'attempts_by_course' => $attemptsByCourse,
        ]);
    }

    public function grantAdmin(Request $request, int $userId): JsonResponse
    {
        $target = User::findOrFail($userId);

        if ($target->is($request->user())) {
            return response()->json(['error' => 'You cannot modify your own admin status.'], 422);
        }

        $target->update(['is_admin' => true]);

        return response()->json([
            'message' => "{$target->name} has been granted admin access.",
            'user_id' => $target->id,
        ]);
    }

    public function togglePro(int $userId): JsonResponse
    {
        $target = User::findOrFail($userId);

        $target->update(['is_pro' => ! $target->is_pro]);

        return response()->json([
            'message' => $target->is_pro
                ? "{$target->name} is now a Pro member."
                : "{$target->name}'s Pro access was removed.",
            'user_id' => $target->id,
            'is_pro'  => $target->is_pro,
        ]);
    }
}
