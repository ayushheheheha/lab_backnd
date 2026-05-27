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
            ->with('user:id,name,email,is_admin')
            ->orderByDesc('logged_in_at')
            ->limit(20)
            ->get()
            ->map(fn (LoginLog $log) => [
                'id'          => $log->id,
                'user_id'     => $log->user_id,
                'name'        => $log->user?->name ?? '—',
                'email'       => $log->user?->email ?? '—',
                'is_admin'    => (bool) ($log->user?->is_admin ?? false),
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
}
