<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminQuizController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $quizzes = Quiz::query()
            ->with([
                'course:id,name',
                'week:id,week_number',
            ])
            ->withCount('questions')
            ->when($request->filled('course_id'), fn ($query) => $query->where('course_id', $request->integer('course_id')))
            ->when($request->filled('section'), fn ($query) => $query->where('section', $request->string('section')))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Quiz $quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'section' => $quiz->section,
                'course_id' => $quiz->course_id,
                'course_name' => $quiz->course?->name,
                'week_id' => $quiz->week_id,
                'week_number' => $quiz->week?->week_number,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'is_active' => (bool) $quiz->is_active,
                'questions_count' => $quiz->questions_count,
                'created_at' => $quiz->created_at,
            ])
            ->values();

        return response()->json($quizzes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'section' => ['required', Rule::in(['practice', 'quiz1', 'quiz2', 'endterm'])],
            'week_id' => ['nullable', 'exists:weeks,id', 'required_if:section,practice'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (($validated['section'] ?? null) !== 'practice') {
            $validated['week_id'] = null;
        }

        $quiz = Quiz::query()->create([
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json(
            $quiz->load([
                'course:id,name',
                'week:id,week_number',
            ])->loadCount('questions'),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $quiz = Quiz::query()
            ->with([
                'course:id,name,slug',
                'week:id,week_number,title',
            ])
            ->withCount('questions')
            ->findOrFail($id);

        return response()->json($quiz);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::query()->findOrFail($id);

        $validated = $request->validate([
            'course_id' => ['sometimes', 'required', 'exists:courses,id'],
            'section' => ['sometimes', 'required', Rule::in(['practice', 'quiz1', 'quiz2', 'endterm'])],
            'week_id' => ['nullable', 'exists:weeks,id'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $nextSection = $validated['section'] ?? $quiz->section;
        if ($nextSection !== 'practice') {
            $validated['week_id'] = null;
        }

        if ($nextSection === 'practice' && ! array_key_exists('week_id', $validated) && ! $quiz->week_id) {
            return response()->json([
                'message' => 'The week_id field is required when section is practice.',
                'errors' => [
                    'week_id' => ['The week_id field is required when section is practice.'],
                ],
            ], 422);
        }

        $quiz->update($validated);

        return response()->json(
            $quiz->fresh()->load([
                'course:id,name,slug',
                'week:id,week_number,title',
            ])->loadCount('questions')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $quiz = Quiz::query()->findOrFail($id);
        $quiz->delete();

        return response()->json([
            'message' => 'Quiz deleted successfully.',
        ]);
    }

    public function toggle(int $id): JsonResponse
    {
        $quiz = Quiz::query()->findOrFail($id);
        $quiz->update([
            'is_active' => ! $quiz->is_active,
        ]);

        return response()->json($quiz->fresh());
    }
}
