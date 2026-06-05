<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\Week;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get([
                'id',
                'name',
                'slug',
                'level',
                'description',
                'icon',
                'has_ide',
            ]);

        return response()->json($courses);
    }

    public function show(string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'weeks' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('week_number')
                    ->getQuery(),
            ])
            ->withCount('ideProblems')
            ->firstOrFail();

        return response()->json([
            'id' => $course->id,
            'name' => $course->name,
            'slug' => $course->slug,
            'description' => $course->description,
            'icon' => $course->icon,
            'has_ide' => (bool) $course->has_ide,
            'has_ide_problems' => $course->ide_problems_count > 0,
            'weeks' => $course->weeks->map(fn ($week) => [
                'id' => $week->id,
                'week_number' => $week->week_number,
                'title' => $week->title,
            ])->values(),
        ]);
    }

    public function weeks(string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $weeks = $course->weeks()
            ->where('is_active', true)
            ->orderBy('week_number')
            ->get(['id', 'week_number', 'title']);

        return response()->json($weeks);
    }

    public function weekQuizzes(Request $request, string $slug, int $weekNumber): JsonResponse
    {
        $user = $request->user();

        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $week = Week::query()
            ->where('course_id', $course->id)
            ->where('week_number', $weekNumber)
            ->where('is_active', true)
            ->firstOrFail();

        $quizzes = $course->quizzes()
            ->where('week_id', $week->id)
            ->whereIn('section', Quiz::WEEKLY_SECTIONS)
            ->where('is_active', true)
            ->withCount('questions')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'description',
                'time_limit_minutes',
                'section',
            ]);

        $attemptMeta = Attempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->selectRaw('quiz_id, MAX(submitted_at) as last_submitted_at, COUNT(*) as attempt_count')
            ->groupBy('quiz_id')
            ->get()
            ->keyBy('quiz_id');

        $mapped = $quizzes->map(fn ($quiz) => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'question_count' => $quiz->questions_count,
            'section' => $quiz->section,
            'user_has_attempted' => $attemptMeta->has($quiz->id),
            'attempt_count' => (int) ($attemptMeta->get($quiz->id)?->attempt_count ?? 0),
            'last_submitted_at' => $attemptMeta->get($quiz->id)?->last_submitted_at,
        ]);

        return response()->json([
            'practice' => $mapped->where('section', 'practice')->values(),
            'graded' => $mapped->where('section', 'practice_graded')->values(),
        ]);
    }

    /**
     * Returns the course plus every weekly practice/graded quiz in a single
     * response. Collapsing the previous per-week request fan-out into one call
     * avoids opening a burst of simultaneous DB connections (which the shared
     * host rejects with SQLSTATE[HY000] [2002] once the connection cap is hit).
     */
    public function practice(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->withCount('ideProblems')
            ->firstOrFail();

        $weeks = $course->weeks()
            ->where('is_active', true)
            ->orderBy('week_number')
            ->get(['id', 'week_number', 'title'])
            ->keyBy('id');

        $quizzes = $course->quizzes()
            ->whereIn('week_id', $weeks->keys())
            ->whereIn('section', Quiz::WEEKLY_SECTIONS)
            ->where('is_active', true)
            ->withCount('questions')
            ->orderBy('week_id')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'description',
                'time_limit_minutes',
                'section',
                'week_id',
            ]);

        $attemptMeta = Attempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->selectRaw('quiz_id, MAX(submitted_at) as last_submitted_at, COUNT(*) as attempt_count')
            ->groupBy('quiz_id')
            ->get()
            ->keyBy('quiz_id');

        $mapped = $quizzes->map(fn ($quiz) => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'question_count' => $quiz->questions_count,
            'section' => $quiz->section,
            'week_number' => $weeks->get($quiz->week_id)?->week_number,
            'user_has_attempted' => $attemptMeta->has($quiz->id),
            'attempt_count' => (int) ($attemptMeta->get($quiz->id)?->attempt_count ?? 0),
            'last_submitted_at' => $attemptMeta->get($quiz->id)?->last_submitted_at,
        ]);

        return response()->json([
            'course' => [
                'id' => $course->id,
                'name' => $course->name,
                'slug' => $course->slug,
                'description' => $course->description,
                'icon' => $course->icon,
                'has_ide' => (bool) $course->has_ide,
                'has_ide_problems' => $course->ide_problems_count > 0,
            ],
            'practice' => $mapped->where('section', 'practice')->values(),
            'graded' => $mapped->where('section', 'practice_graded')->values(),
        ]);
    }

    public function examPrep(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $allQuizzes = $course->quizzes()
            ->whereIn('section', ['quiz1', 'quiz2', 'endterm', 'mock_test'])
            ->where('is_active', true)
            ->withCount('questions')
            ->orderByDesc('year')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'description',
                'time_limit_minutes',
                'section',
                'year',
            ]);

        $attemptMeta = Attempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $allQuizzes->pluck('id'))
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->selectRaw('quiz_id, MAX(submitted_at) as last_submitted_at, COUNT(*) as attempt_count')
            ->groupBy('quiz_id')
            ->get()
            ->keyBy('quiz_id');

        $mapped = $allQuizzes->map(fn ($quiz) => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'question_count' => $quiz->questions_count,
            'section' => $quiz->section,
            'year' => $quiz->year,
            'user_has_attempted' => $attemptMeta->has($quiz->id),
            'attempt_count' => (int) ($attemptMeta->get($quiz->id)?->attempt_count ?? 0),
            'last_submitted_at' => $attemptMeta->get($quiz->id)?->last_submitted_at,
        ]);

        $bySection = fn (string $section) => $mapped->where('section', $section)->values();

        return response()->json([
            'quiz1' => $bySection('quiz1'),
            'quiz2' => $bySection('quiz2'),
            'endterm' => $bySection('endterm'),
            'mock_test' => $bySection('mock_test'),
        ]);
    }
}
