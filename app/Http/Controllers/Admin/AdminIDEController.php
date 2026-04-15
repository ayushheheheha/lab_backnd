<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IDEProblem;
use App\Models\IDETestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminIDEController extends Controller
{
    public function index(): JsonResponse
    {
        $problems = IDEProblem::query()
            ->with('course:id,name')
            ->withCount([
                'testCases as test_cases_count',
                'testCases as hidden_test_cases_count' => fn ($query) => $query->where('is_hidden', true),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IDEProblem $problem) => [
                'id' => $problem->id,
                'title' => $problem->title,
                'course_id' => $problem->course_id,
                'course_name' => $problem->course?->name,
                'difficulty' => $problem->difficulty,
                'language' => $problem->language,
                'is_active' => (bool) $problem->is_active,
                'week_number' => $problem->week_number,
                'test_cases_count' => (int) $problem->test_cases_count,
                'hidden_test_cases_count' => (int) $problem->hidden_test_cases_count,
            ]);

        return response()->json($problems);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'constraints' => ['nullable', 'string'],
            'input_format' => ['nullable', 'string'],
            'output_format' => ['nullable', 'string'],
            'sample_input' => ['required', 'string'],
            'sample_output' => ['required', 'string'],
            'difficulty' => ['required', Rule::in(['easy', 'medium', 'hard'])],
            'week_number' => ['nullable', 'integer', 'min:1', 'max:52'],
            'language' => ['required', Rule::in(['python', 'java', 'cpp'])],
            'time_limit_seconds' => ['required', 'integer', 'min:1', 'max:60'],
            'memory_limit_mb' => ['required', 'integer', 'min:64', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'test_cases' => ['nullable', 'array'],
            'test_cases.*.input' => ['required_with:test_cases', 'string'],
            'test_cases.*.expected_output' => ['required_with:test_cases', 'string'],
            'test_cases.*.is_hidden' => ['nullable', 'boolean'],
        ]);

        $problem = IDEProblem::query()->create([
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        foreach (($validated['test_cases'] ?? []) as $index => $testCase) {
            $problem->testCases()->create([
                'input' => $testCase['input'],
                'expected_output' => $testCase['expected_output'],
                'is_hidden' => (bool) ($testCase['is_hidden'] ?? false),
                'position' => $index,
            ]);
        }

        return response()->json($problem->load('testCases'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $problem = IDEProblem::query()
            ->with([
                'course:id,name,slug',
                'testCases' => fn ($query) => $query->orderBy('position'),
            ])
            ->findOrFail($id);

        return response()->json($problem);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $problem = IDEProblem::query()->findOrFail($id);

        $validated = $request->validate([
            'course_id' => ['sometimes', 'required', 'exists:courses,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'constraints' => ['nullable', 'string'],
            'input_format' => ['nullable', 'string'],
            'output_format' => ['nullable', 'string'],
            'sample_input' => ['sometimes', 'required', 'string'],
            'sample_output' => ['sometimes', 'required', 'string'],
            'difficulty' => ['sometimes', 'required', Rule::in(['easy', 'medium', 'hard'])],
            'week_number' => ['nullable', 'integer', 'min:1', 'max:52'],
            'language' => ['sometimes', 'required', Rule::in(['python', 'java', 'cpp'])],
            'time_limit_seconds' => ['sometimes', 'required', 'integer', 'min:1', 'max:60'],
            'memory_limit_mb' => ['sometimes', 'required', 'integer', 'min:64', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $problem->update($validated);

        return response()->json($problem->fresh()->load('testCases'));
    }

    public function destroy(int $id): JsonResponse
    {
        $problem = IDEProblem::query()->findOrFail($id);
        $problem->delete();

        return response()->json([
            'message' => 'IDE problem deleted successfully.',
        ]);
    }

    public function addTestCase(Request $request, int $id): JsonResponse
    {
        $problem = IDEProblem::query()->findOrFail($id);

        $validated = $request->validate([
            'input' => ['required', 'string'],
            'expected_output' => ['required', 'string'],
            'is_hidden' => ['nullable', 'boolean'],
        ]);

        $position = (int) $problem->testCases()->max('position') + 1;

        $testCase = $problem->testCases()->create([
            'input' => $validated['input'],
            'expected_output' => $validated['expected_output'],
            'is_hidden' => (bool) ($validated['is_hidden'] ?? false),
            'position' => $position,
        ]);

        return response()->json($testCase, 201);
    }

    public function updateTestCase(Request $request, int $id): JsonResponse
    {
        $testCase = IDETestCase::query()->findOrFail($id);

        $validated = $request->validate([
            'input' => ['sometimes', 'required', 'string'],
            'expected_output' => ['sometimes', 'required', 'string'],
            'is_hidden' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $testCase->update($validated);

        return response()->json($testCase->fresh());
    }

    public function deleteTestCase(int $id): JsonResponse
    {
        $testCase = IDETestCase::query()->findOrFail($id);
        $testCase->delete();

        return response()->json([
            'message' => 'Test case deleted successfully.',
        ]);
    }
}
