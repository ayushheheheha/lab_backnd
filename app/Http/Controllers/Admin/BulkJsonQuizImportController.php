<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\Week;
use App\Services\QuestionImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bulk JSON quiz import.
 *
 * Accepts ONE course plus many .json files. Each file is a self-contained quiz
 * definition (its own title, section, week, and questions) and becomes its own
 * separate quiz. Every file is processed independently in its own transaction,
 * so a single malformed file never aborts the rest of the batch.
 */
class BulkJsonQuizImportController extends Controller
{
    /**
     * Friendly section aliases an admin may type in the JSON. Canonical
     * Quiz::SECTIONS values also pass through unchanged.
     */
    private const SECTION_ALIASES = [
        'practice'             => 'practice',
        'pa'                   => 'practice',
        'practice_assignment'  => 'practice',
        'practice assignment'  => 'practice',
        'graded'               => 'practice_graded',
        'ga'                   => 'practice_graded',
        'practice_graded'      => 'practice_graded',
        'graded_assignment'    => 'practice_graded',
        'graded assignment'    => 'practice_graded',
        'quiz1'                => 'quiz1',
        'quiz 1'               => 'quiz1',
        'quiz_1'               => 'quiz1',
        'quiz2'                => 'quiz2',
        'quiz 2'               => 'quiz2',
        'quiz_2'               => 'quiz2',
        'endterm'              => 'endterm',
        'end term'             => 'endterm',
        'end_term'             => 'endterm',
        'end-term'             => 'endterm',
        'mock_test'            => 'mock_test',
        'mock test'            => 'mock_test',
        'mocktest'             => 'mock_test',
        'mock'                 => 'mock_test',
    ];

    public function __invoke(Request $request, QuestionImporter $importer): JsonResponse
    {
        @set_time_limit(0);

        $validated = $request->validate([
            'course_id'    => ['required', 'integer', 'exists:courses,id'],
            'json_files'   => ['required', 'array', 'min:1', 'max:50'],
            'json_files.*' => ['file', 'mimes:json,txt', 'max:10240'],
        ]);

        $courseId = (int) $validated['course_id'];
        /** @var UploadedFile[] $files */
        $files = $request->file('json_files');

        $results = [];
        $createdCount = 0;
        $failedCount = 0;

        foreach ($files as $file) {
            $name = $file->getClientOriginalName() ?: 'file.json';
            try {
                $result = $this->importOneFile($file, $courseId, $importer);
                $results[] = [
                    'file'           => $name,
                    'status'         => 'created',
                    'quiz_id'        => $result['quiz_id'],
                    'title'          => $result['title'],
                    'section'        => $result['section'],
                    'week_number'    => $result['week_number'],
                    'imported_count' => $result['imported_count'],
                    'warnings'       => $result['warnings'],
                ];
                $createdCount++;
            } catch (ImportFileException $e) {
                $results[] = [
                    'file'   => $name,
                    'status' => 'failed',
                    'error'  => $e->getMessage(),
                ];
                $failedCount++;
            } catch (Throwable $e) {
                Log::error('Bulk JSON quiz import failed for a file', [
                    'file'  => $name,
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'file'   => $name,
                    'status' => 'failed',
                    'error'  => 'Unexpected error: '.$e->getMessage(),
                ];
                $failedCount++;
            }
        }

        return response()->json([
            'created_count' => $createdCount,
            'failed_count'  => $failedCount,
            'results'       => $results,
        ], $createdCount > 0 ? 201 : 422);
    }

    /**
     * Parse and import a single file into a brand-new quiz.
     *
     * @return array{quiz_id:int,title:string,section:string,week_number:?int,imported_count:int,warnings:array}
     *
     * @throws ImportFileException for any user-facing, per-file validation failure.
     */
    private function importOneFile(UploadedFile $file, int $courseId, QuestionImporter $importer): array
    {
        $raw = trim((string) file_get_contents($file->getRealPath()));
        if ($raw === '') {
            throw new ImportFileException('File is empty.');
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new ImportFileException('Invalid JSON: '.json_last_error_msg());
        }

        // Section (type) — required, friendly aliases accepted.
        $section = $this->resolveSection($decoded['section'] ?? ($decoded['type'] ?? null));

        // Week — required for weekly sections, resolved to a week_id within the course.
        $needsWeek = in_array($section, Quiz::WEEKLY_SECTIONS, true);
        $weekId = null;
        $weekNumber = null;
        if ($needsWeek) {
            $rawWeek = $decoded['week'] ?? ($decoded['week_number'] ?? null);
            if ($rawWeek === null || $rawWeek === '' || ! is_numeric($rawWeek)) {
                throw new ImportFileException('A numeric "week" is required for the '.$section.' section.');
            }
            $weekNumber = (int) $rawWeek;
            $week = Week::query()
                ->where('course_id', $courseId)
                ->where('week_number', $weekNumber)
                ->first();
            if (! $week) {
                throw new ImportFileException('Week '.$weekNumber.' does not exist for the selected course.');
            }
            $weekId = $week->id;
        }

        // Questions — required, non-empty.
        $questions = $decoded['questions'] ?? null;
        if (! is_array($questions) || empty($questions)) {
            throw new ImportFileException('JSON must contain a non-empty "questions" array.');
        }

        $normalizeResult = $importer->normalizeMany($questions);
        $normalizedRows = $normalizeResult['rows'];
        $warnings = $normalizeResult['errors'];

        if (empty($normalizedRows)) {
            throw new ImportFileException('No valid questions found in the file.');
        }

        // Title — JSON value, else the file name (sans extension), else a fallback.
        $title = trim((string) ($decoded['quiz_title'] ?? ($decoded['title'] ?? '')));
        if ($title === '') {
            $base = pathinfo($file->getClientOriginalName() ?: '', PATHINFO_FILENAME);
            $title = trim($base) !== '' ? trim($base) : 'Imported Quiz';
        }

        $description = trim((string) ($decoded['description'] ?? ''));
        $timeLimit = $decoded['time_limit_minutes'] ?? null;
        $timeLimit = (is_numeric($timeLimit) && (int) $timeLimit > 0) ? (int) $timeLimit : null;

        $quiz = DB::transaction(function () use ($courseId, $weekId, $section, $title, $description, $timeLimit, $normalizedRows, $importer) {
            $quiz = Quiz::query()->create([
                'course_id'          => $courseId,
                'week_id'            => $weekId,
                'section'            => $section,
                'title'              => $title,
                'description'        => $description !== '' ? $description : null,
                'time_limit_minutes' => $timeLimit,
                'is_active'          => true,
            ]);

            $importer->persist($quiz->id, $normalizedRows);

            return $quiz;
        });

        return [
            'quiz_id'        => $quiz->id,
            'title'          => $quiz->title,
            'section'        => $section,
            'week_number'    => $weekNumber,
            'imported_count' => count($normalizedRows),
            'warnings'       => $warnings,
        ];
    }

    /**
     * @throws ImportFileException when the section is missing or unrecognised.
     */
    private function resolveSection(mixed $raw): string
    {
        $value = strtolower(trim((string) ($raw ?? '')));
        if ($value === '') {
            throw new ImportFileException('A "section" is required (e.g. practice, graded, quiz1, quiz2, endterm, mock_test).');
        }

        if (in_array($value, Quiz::SECTIONS, true)) {
            return $value;
        }

        if (isset(self::SECTION_ALIASES[$value])) {
            return self::SECTION_ALIASES[$value];
        }

        throw new ImportFileException(
            'Unknown section "'.$raw.'". Use one of: practice, graded, quiz1, quiz2, endterm, mock_test.'
        );
    }
}

/**
 * Internal, user-facing failure for a single file in the batch. The message is
 * surfaced verbatim to the admin alongside the offending file name.
 */
class ImportFileException extends \RuntimeException
{
}
