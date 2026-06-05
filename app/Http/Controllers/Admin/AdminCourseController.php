<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'slug' => ['nullable', 'string', 'max:120', 'unique:courses,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:20'],
            'has_ide' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            // How many weeks to provision so the weekly categories
            // (Practice / PA / GA) are usable right away.
            'weeks_count' => ['nullable', 'integer', 'min:0', 'max:52'],
        ]);

        $slug = ! empty($validated['slug'])
            ? $validated['slug']
            : $this->uniqueSlug($validated['name']);

        $weeksCount = (int) ($validated['weeks_count'] ?? 0);

        $course = DB::transaction(function () use ($validated, $slug, $weeksCount) {
            $course = Course::query()->create([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? '📚',
                'has_ide' => (bool) ($validated['has_ide'] ?? false),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'sort_order' => $validated['sort_order'] ?? ((int) Course::query()->max('sort_order') + 1),
            ]);

            for ($week = 1; $week <= $weeksCount; $week++) {
                $course->weeks()->create([
                    'week_number' => $week,
                    'title' => 'Week '.$week,
                    'is_active' => true,
                ]);
            }

            return $course;
        });

        return response()->json($course, 201);
    }

    /**
     * Build a URL-safe, unique slug from the subject name.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'subject';
        $slug = $base;
        $suffix = 2;

        while (Course::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
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
