<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\QuestionImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class JsonQuizImportController extends Controller
{
    public function __invoke(Request $request, QuestionImporter $importer): JsonResponse
    {
        @set_time_limit(0);

        $validated = $request->validate([
            'json'           => ['nullable', 'string'],
            'json_file'      => ['nullable', 'file', 'mimes:json,txt', 'max:10240'],
            'course_id'      => ['required', 'integer', 'exists:courses,id'],
            'section'        => ['required', Rule::in(Quiz::SECTIONS)],
            'week_id'        => ['nullable', 'integer', 'exists:weeks,id'],
            'title_override' => ['nullable', 'string', 'max:200'],
        ]);

        $needsWeek = in_array($validated['section'], Quiz::WEEKLY_SECTIONS, true);
        if ($needsWeek && empty($validated['week_id'])) {
            return response()->json([
                'error' => 'Week is required for the '.$validated['section'].' section.',
            ], 422);
        }

        $jsonString = trim((string) ($validated['json'] ?? ''));
        if ($jsonString === '' && $request->hasFile('json_file')) {
            $jsonString = (string) file_get_contents($request->file('json_file')->getRealPath());
            $jsonString = trim($jsonString);
        }

        if ($jsonString === '') {
            return response()->json([
                'error' => 'Provide a JSON payload via the textarea or as a .json file.',
            ], 422);
        }

        $decoded = json_decode($jsonString, true);
        if (! is_array($decoded)) {
            return response()->json([
                'error' => 'Invalid JSON: '.json_last_error_msg(),
            ], 422);
        }

        $questions = $decoded['questions'] ?? null;
        if (! is_array($questions) || empty($questions)) {
            return response()->json([
                'error' => 'JSON must contain a non-empty "questions" array.',
            ], 422);
        }

        $normalizeResult = $importer->normalizeMany($questions);
        $normalizedRows = $normalizeResult['rows'];
        $warnings = $normalizeResult['errors'];

        if (empty($normalizedRows)) {
            return response()->json([
                'error' => 'No valid questions found in the JSON.',
                'warnings' => $warnings,
            ], 422);
        }

        $titleOverride = trim((string) ($validated['title_override'] ?? ''));
        $jsonTitle = trim((string) ($decoded['quiz_title'] ?? ''));
        $finalTitle = $titleOverride !== ''
            ? $titleOverride
            : ($jsonTitle !== '' ? $jsonTitle : 'Imported Quiz');

        try {
            $quiz = DB::transaction(function () use ($validated, $needsWeek, $finalTitle, $normalizedRows, $importer) {
                $quiz = Quiz::query()->create([
                    'course_id'          => (int) $validated['course_id'],
                    'week_id'            => $needsWeek ? (int) $validated['week_id'] : null,
                    'section'            => $validated['section'],
                    'title'              => $finalTitle,
                    'description'        => null,
                    'time_limit_minutes' => null,
                    'is_active'          => true,
                ]);

                $importer->persist($quiz->id, $normalizedRows);

                return $quiz;
            });
        } catch (Throwable $e) {
            Log::error('JSON quiz import failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Unable to import quiz: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'quiz_id'        => $quiz->id,
            'title'          => $quiz->title,
            'imported_count' => count($normalizedRows),
            'warnings'       => $warnings,
        ], 201);
    }
}
