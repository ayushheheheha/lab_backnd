<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCourseController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::query()
            ->orderBy('sort_order')
            ->get([
                'id',
                'name',
                'slug',
                'description',
                'icon',
                'has_ide',
                'is_active',
                'sort_order',
            ]);

        return response()->json($courses);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'unique:courses,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:20'],
            'has_ide' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $course = Course::query()->create([
            ...$validated,
            'has_ide' => (bool) ($validated['has_ide'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json($course, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $course = Course::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:20'],
            'has_ide' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $course->update($validated);

        return response()->json($course->fresh());
    }

    public function toggle(int $id): JsonResponse
    {
        $course = Course::query()->findOrFail($id);
        $course->update([
            'is_active' => ! $course->is_active,
        ]);

        return response()->json($course->fresh());
    }
}
