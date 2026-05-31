<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\VideoImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class JsonVideoImportController extends Controller
{
    public function __invoke(Request $request, VideoImporter $importer): JsonResponse
    {
        @set_time_limit(0);

        $validated = $request->validate([
            'json'          => ['nullable', 'string'],
            'json_file'     => ['nullable', 'file', 'mimes:json,txt', 'max:10240'],
            // Importer-wide fallbacks. Per-video values in the JSON win over these.
            'course_id'     => ['nullable', 'integer', 'exists:courses,id'],
            'is_pro'        => ['nullable', 'boolean'],
            'is_published'  => ['nullable', 'boolean'],
        ]);

        $jsonString = trim((string) ($validated['json'] ?? ''));
        if ($jsonString === '' && $request->hasFile('json_file')) {
            $jsonString = trim((string) file_get_contents($request->file('json_file')->getRealPath()));
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

        // Accept either { "videos": [...] } or a bare top-level array.
        $videos = $decoded['videos'] ?? (array_is_list($decoded) ? $decoded : null);
        if (! is_array($videos) || empty($videos)) {
            return response()->json([
                'error' => 'JSON must contain a non-empty "videos" array.',
            ], 422);
        }

        $defaults = [
            'course_id'    => $validated['course_id'] ?? null,
            'is_pro'       => $request->has('is_pro') ? (bool) $validated['is_pro'] : null,
            'is_published' => $request->has('is_published') ? (bool) $validated['is_published'] : null,
        ];

        $result = $importer->normalizeMany($videos, $defaults);
        $rows = $result['rows'];
        $warnings = $result['errors'];

        if (empty($rows)) {
            return response()->json([
                'error'    => 'No valid videos found in the JSON.',
                'warnings' => $warnings,
            ], 422);
        }

        try {
            $insertedIds = $importer->persist($rows);
        } catch (Throwable $e) {
            Log::error('JSON video import failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Unable to import videos: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'imported_count' => count($insertedIds),
            'warnings'       => $warnings,
        ], 201);
    }
}
