<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Week;
use Illuminate\Http\JsonResponse;

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

    public function weekQuizzes(string $slug, int $weekNumber): JsonResponse
    {
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
            ->where('section', 'practice')
            ->where('is_active', true)
            ->withCount('questions')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'description',
                'time_limit_minutes',
            ])
            ->map(fn ($quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'question_count' => $quiz->questions_count,
            ])
            ->values();

        return response()->json($quizzes);
    }

    public function examPrep(string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $mapSection = fn (string $section) => $course->quizzes()
            ->where('section', $section)
            ->where('is_active', true)
            ->withCount('questions')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'description',
                'time_limit_minutes',
            ])
            ->map(fn ($quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'question_count' => $quiz->questions_count,
            ])
            ->values();

        return response()->json([
            'quiz1' => $mapSection('quiz1'),
            'quiz2' => $mapSection('quiz2'),
            'endterm' => $mapSection('endterm'),
        ]);
    }
}
