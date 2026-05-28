<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\DeepSeekGenerationException;
use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\DeepSeekQuizGenerator;
use App\Services\QuestionImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class PdfQuizImportController extends Controller
{
    public function __invoke(
        Request $request,
        DeepSeekQuizGenerator $generator,
        QuestionImporter $importer
    ): JsonResponse {
        // DeepSeek can take several minutes for large PDFs; override PHP's
        // default max_execution_time (60s on most Laragon installs).
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimetypes:application/pdf', 'mimes:pdf', 'max:10240'],
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'section' => ['required', Rule::in(Quiz::SECTIONS)],
            'week_id' => ['nullable', 'integer', 'exists:weeks,id'],
            'title_override' => ['nullable', 'string', 'max:200'],
        ]);

        $needsWeek = in_array($validated['section'], Quiz::WEEKLY_SECTIONS, true);
        if ($needsWeek && empty($validated['week_id'])) {
            return response()->json([
                'error' => 'Week is required for the '.$validated['section'].' section.',
            ], 422);
        }

        $uploaded = $request->file('pdf');
        $storedPath = $uploaded->store('tmp-pdfs', 'local');
        $absolutePath = storage_path('app/'.$storedPath);

        try {
            $pdfText = $this->extractPdfText($absolutePath);
            if (trim($pdfText) === '') {
                return response()->json([
                    'error' => 'PDF appears to have no extractable text. Is it a scanned image?',
                ], 422);
            }

            try {
                $generated = $generator->generateFromText($pdfText);
            } catch (DeepSeekGenerationException $e) {
                Log::error('DeepSeek generation failed', ['error' => $e->getMessage()]);
                return response()->json([
                    'error' => 'Unable to generate quiz from PDF. '.$e->getMessage(),
                ], 502);
            }

            $normalizeResult = $importer->normalizeMany($generated['questions'] ?? []);
            $normalizedRows = $normalizeResult['rows'];
            $warnings = $normalizeResult['errors'];

            if (empty($normalizedRows)) {
                return response()->json([
                    'error' => 'Generated questions failed validation.',
                    'warnings' => $warnings,
                ], 422);
            }

            $titleOverride = trim((string) ($validated['title_override'] ?? ''));
            $finalTitle = $titleOverride !== '' ? $titleOverride : trim((string) $generated['quiz_title']);

            $quiz = DB::transaction(function () use ($validated, $needsWeek, $finalTitle, $normalizedRows, $importer) {
                $quiz = Quiz::query()->create([
                    'course_id' => (int) $validated['course_id'],
                    'week_id' => $needsWeek ? (int) $validated['week_id'] : null,
                    'section' => $validated['section'],
                    'title' => $finalTitle,
                    'description' => null,
                    'time_limit_minutes' => null,
                    'is_active' => true,
                ]);

                $importer->persist($quiz->id, $normalizedRows);

                return $quiz;
            });

            return response()->json([
                'quiz_id' => $quiz->id,
                'title' => $quiz->title,
                'imported_count' => count($normalizedRows),
                'warnings' => $warnings,
            ], 201);
        } catch (Throwable $e) {
            Log::error('PDF import failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Unable to process PDF: '.$e->getMessage(),
            ], 500);
        } finally {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    private function extractPdfText(string $absolutePath): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($absolutePath);
            return $pdf->getText();
        } catch (Throwable $e) {
            Log::warning('PDF parse failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Could not read PDF file.');
        }
    }
}
