<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\IDEProblem;
use App\Models\IDESubmission;
use App\Services\CodeExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IDEController extends Controller
{
    public function __construct(private readonly CodeExecutionService $executionService)
    {
    }

    public function bySlug(string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $problems = IDEProblem::query()
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->orderByRaw('COALESCE(week_number, 9999)')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'difficulty',
                'week_number',
                'language',
            ]);

        $grouped = $problems
            ->groupBy(fn ($problem) => $problem->week_number ?? 0)
            ->map(fn ($items, $weekNumber) => [
                'week_number' => (int) $weekNumber,
                'problems' => $items->values(),
            ])
            ->values();

        return response()->json([
            'course' => [
                'id' => $course->id,
                'name' => $course->name,
                'slug' => $course->slug,
            ],
            'weeks' => $grouped,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $problem = IDEProblem::query()
            ->with([
                'course:id,name,slug',
                'testCases' => fn ($query) => $query
                    ->where('is_hidden', false)
                    ->orderBy('position')
                    ->getQuery(),
            ])
            ->findOrFail($id);

        $response = [
            'problem' => $problem,
        ];

        if ($request->filled('submission_id')) {
            $submission = IDESubmission::query()
                ->where('id', $request->integer('submission_id'))
                ->where('ide_problem_id', $problem->id)
                ->where('user_id', $request->user()->id)
                ->first();

            if ($submission) {
                $response['selected_submission'] = [
                    'id' => $submission->id,
                    'language' => $submission->language,
                    'code' => $submission->code,
                    'status' => $submission->status,
                    'submitted_at' => $submission->submitted_at,
                ];
            }
        }

        return response()->json($response);
    }

    public function run(Request $request, int $id): JsonResponse
    {
        $problem = IDEProblem::query()
            ->with(['testCases' => fn ($query) => $query
                ->where('is_hidden', false)
                ->orderBy('position')
                ->getQuery()])
            ->findOrFail($id);

        $validated = $request->validate([
            'language' => ['required', 'in:python,java,cpp,c++'],
            'code' => ['required', 'string'],
        ]);

        $results = [];
        foreach ($problem->testCases as $index => $case) {
            $normalizedInput = $this->normalizeProblemText((string) $case->input);
            $normalizedExpected = $this->normalizeProblemText((string) $case->expected_output);

            $execution = $this->executionService->execute(
                $validated['language'],
                $validated['code'],
                $normalizedInput,
                (int) ($problem->time_limit_seconds ?: 5)
            );

            $got = trim((string) ($execution['stdout'] ?? ''));
            $expected = trim($normalizedExpected);

            $results[] = [
                'case_number' => $index + 1,
                'input' => $normalizedInput,
                'expected' => $normalizedExpected,
                'got' => $got,
                'passed' => $got === $expected,
            ];
        }

        return response()->json([
            'test_results' => $results,
        ]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $problem = IDEProblem::query()
            ->with(['testCases' => fn ($query) => $query->orderBy('position')])
            ->findOrFail($id);

        $validated = $request->validate([
            'language' => ['required', 'in:python,java,cpp,c++'],
            'code' => ['required', 'string'],
        ]);

        $status = 'accepted';
        $results = [];
        $passedCases = 0;

        foreach ($problem->testCases as $index => $case) {
            $normalizedInput = $this->normalizeProblemText((string) $case->input);
            $normalizedExpected = $this->normalizeProblemText((string) $case->expected_output);

            $execution = $this->executionService->execute(
                $validated['language'],
                $validated['code'],
                $normalizedInput,
                (int) ($problem->time_limit_seconds ?: 5)
            );

            $stderr = trim((string) ($execution['stderr'] ?? ''));
            $got = trim((string) ($execution['stdout'] ?? ''));
            $expected = trim($normalizedExpected);

            $isTimeout = str_contains(strtolower($stderr), 'time')
                && str_contains(strtolower($stderr), 'out');
            $passed = $got === $expected && $stderr === '';

            if ($passed) {
                $passedCases++;
            }

            if (! $passed && $status === 'accepted') {
                if ($isTimeout) {
                    $status = 'time_limit_exceeded';
                } elseif ($stderr !== '') {
                    $status = 'runtime_error';
                } else {
                    $status = 'wrong_answer';
                }
            }

            if ($case->is_hidden) {
                $results[] = [
                    'case_number' => $index + 1,
                    'passed' => $passed,
                    'is_hidden' => true,
                ];
            } else {
                $results[] = [
                    'case_number' => $index + 1,
                    'input' => $normalizedInput,
                    'expected' => $normalizedExpected,
                    'got' => $got,
                    'passed' => $passed,
                    'is_hidden' => false,
                ];
            }
        }

        $submission = IDESubmission::query()->create([
            'ide_problem_id' => $problem->id,
            'user_id' => $request->user()->id,
            'language' => $validated['language'],
            'code' => $validated['code'],
            'status' => $status,
            'test_results' => $results,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'submission_id' => $submission->id,
            'status' => $status,
            'test_results' => $results,
            'total_cases' => count($results),
            'passed_cases' => $passedCases,
        ]);
    }

    public function mySubmissions(Request $request, int $id): JsonResponse
    {
        IDEProblem::query()->findOrFail($id);

        $submissions = IDESubmission::query()
            ->where('ide_problem_id', $id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('submitted_at')
            ->limit(20)
            ->get([
                'id',
                'status',
                'language',
                'submitted_at',
            ]);

        return response()->json($submissions);
    }

    private function normalizeProblemText(string $value): string
    {
        return str_replace(["\\r\\n", "\\n"], ["\n", "\n"], $value);
    }
}
