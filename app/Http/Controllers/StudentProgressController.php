<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Course;
use App\Models\IDESubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentProgressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $completedAttempts = Attempt::query()
            ->where('user_id', $user->id)
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->get(['quiz_id', 'score', 'total_marks']);

        $attemptedQuizIds = $completedAttempts
            ->pluck('quiz_id')
            ->filter()
            ->unique()
            ->values();

        $averagePercentage = $completedAttempts->count() > 0
            ? round($completedAttempts->avg(function ($attempt) {
                $total = (float) ($attempt->total_marks ?? 0);
                if ($total <= 0) {
                    return 0;
                }

                return ((float) ($attempt->score ?? 0) / $total) * 100;
            }), 2)
            : 0;

        $ideSubmissionCount = IDESubmission::query()
            ->where('user_id', $user->id)
            ->count();

        $ideSolvedCount = IDESubmission::query()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->distinct('ide_problem_id')
            ->count('ide_problem_id');

        $courses = Course::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with([
                'weeks' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('week_number'),
                'quizzes' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('week_id')
                    ->where('section', 'practice')
                    ->with(['week:id,week_number']),
            ])
            ->get(['id', 'name', 'slug', 'icon']);

        $courseProgress = $courses->map(function ($course) use ($attemptedQuizIds) {
            $weekRows = $course->weeks->map(function ($week) use ($course, $attemptedQuizIds) {
                $weekQuizIds = $course->quizzes
                    ->where('week_id', $week->id)
                    ->pluck('id')
                    ->values();

                $attemptedCount = $weekQuizIds
                    ->intersect($attemptedQuizIds)
                    ->count();

                $totalWeekQuizzes = $weekQuizIds->count();

                return [
                    'week_number' => $week->week_number,
                    'title' => $week->title,
                    'total_quizzes' => $totalWeekQuizzes,
                    'attempted_quizzes' => $attemptedCount,
                    'is_completed' => $totalWeekQuizzes > 0 && $attemptedCount >= $totalWeekQuizzes,
                ];
            })->values();

            $courseQuizIds = $course->quizzes->pluck('id')->values();
            $attemptedInCourse = $courseQuizIds
                ->intersect($attemptedQuizIds)
                ->count();

            $totalCourseQuizzes = $courseQuizIds->count();
            $completion = $totalCourseQuizzes > 0
                ? round(($attemptedInCourse / $totalCourseQuizzes) * 100, 2)
                : 0;

            return [
                'course_id' => $course->id,
                'course_name' => $course->name,
                'course_slug' => $course->slug,
                'icon' => $course->icon,
                'total_quizzes' => $totalCourseQuizzes,
                'attempted_quizzes' => $attemptedInCourse,
                'completion_percent' => $completion,
                'is_completed' => $totalCourseQuizzes > 0 && $attemptedInCourse >= $totalCourseQuizzes,
                'weeks' => $weekRows,
            ];
        })->values();

        $recentQuizAttempts = Attempt::query()
            ->where('user_id', $user->id)
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->with(['quiz:id,course_id,title', 'quiz.course:id,name,slug'])
            ->latest('submitted_at')
            ->limit(6)
            ->get()
            ->map(function ($attempt) {
                $score = (float) ($attempt->score ?? 0);
                $totalMarks = (float) ($attempt->total_marks ?? 0);
                $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;

                return [
                    'type' => 'quiz',
                    'title' => $attempt->quiz?->title,
                    'course_name' => $attempt->quiz?->course?->name,
                    'course_slug' => $attempt->quiz?->course?->slug,
                    'quiz_id' => $attempt->quiz_id,
                    'attempt_id' => $attempt->id,
                    'submitted_at' => $attempt->submitted_at,
                    'percentage' => $percentage,
                ];
            });

        $recentIdeSubmissions = IDESubmission::query()
            ->where('user_id', $user->id)
            ->with('ideProblem:id,title,course_id')
            ->latest('submitted_at')
            ->limit(6)
            ->get()
            ->map(function ($submission) {
                return [
                    'type' => 'ide',
                    'title' => $submission->ideProblem?->title,
                    'ide_problem_id' => $submission->ide_problem_id,
                    'status' => $submission->status,
                    'submitted_at' => $submission->submitted_at,
                ];
            });

        $recentActivity = $recentQuizAttempts
            ->concat($recentIdeSubmissions)
            ->sortByDesc('submitted_at')
            ->take(8)
            ->values();

        return response()->json([
            'overview' => [
                'quizzes_attempted' => $attemptedQuizIds->count(),
                'completed_attempts' => $completedAttempts->count(),
                'avg_percentage' => $averagePercentage,
                'courses_with_progress' => $courseProgress->where('attempted_quizzes', '>', 0)->count(),
                'ide_submissions' => $ideSubmissionCount,
                'ide_solved' => $ideSolvedCount,
            ],
            'course_progress' => $courseProgress,
            'recent_activity' => $recentActivity,
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        $totalAttempts = Attempt::query()
            ->where('user_id', $user->id)
            ->where('is_complete', true)
            ->count();

        $avgPercentage = Attempt::query()
            ->where('user_id', $user->id)
            ->where('is_complete', true)
            ->whereNotNull('total_marks')
            ->where('total_marks', '>', 0)
            ->selectRaw('AVG((score / total_marks) * 100) as avg_percentage')
            ->value('avg_percentage');

        $bestPercentage = Attempt::query()
            ->where('user_id', $user->id)
            ->where('is_complete', true)
            ->whereNotNull('total_marks')
            ->where('total_marks', '>', 0)
            ->selectRaw('MAX((score / total_marks) * 100) as best_percentage')
            ->value('best_percentage');

        $ideAccepted = IDESubmission::query()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->distinct('ide_problem_id')
            ->count('ide_problem_id');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'joined_at' => $user->created_at,
            ],
            'stats' => [
                'completed_attempts' => $totalAttempts,
                'avg_percentage' => round((float) ($avgPercentage ?? 0), 2),
                'best_percentage' => round((float) ($bestPercentage ?? 0), 2),
                'ide_solved' => $ideAccepted,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'avatar' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ]);

        $payload = [];

        if (array_key_exists('name', $validated)) {
            $payload['name'] = $validated['name'];
        }

        $removeAvatar = (bool) ($validated['remove_avatar'] ?? false);
        if ($removeAvatar && $user->avatar && str_starts_with($user->avatar, '/storage/avatars/')) {
            $oldPath = ltrim(str_replace('/storage/', '', $user->avatar), '/');
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
            $payload['avatar'] = null;
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && str_starts_with($user->avatar, '/storage/avatars/')) {
                $oldPath = ltrim(str_replace('/storage/', '', $user->avatar), '/');
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $payload['avatar'] = Storage::url($path);
        }

        if (! empty($payload)) {
            $user->update($payload);
        }

        $user = $user->fresh();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ],
        ]);
    }
}
