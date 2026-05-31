<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoSolution;
use App\Services\VideoImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVideoSolutionController extends Controller
{
    public function index(): JsonResponse
    {
        $videos = VideoSolution::query()
            ->with('course:id,name,slug')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($videos);
    }

    public function store(Request $request, VideoImporter $importer): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $source = $this->resolveSource($request, $importer);
        if ($source === null) {
            return response()->json([
                'message' => 'Provide a valid YouTube or Google Drive link / id.',
            ], 422);
        }

        $video = VideoSolution::query()->create([...$validated, ...$source]);

        return response()->json($video->load('course:id,name,slug'), 201);
    }

    public function update(Request $request, int $id, VideoImporter $importer): JsonResponse
    {
        $video = VideoSolution::query()->findOrFail($id);

        $validated = $this->validatePayload($request);

        // Only re-resolve the video source if a link/id was supplied.
        if ($this->hasSourceInput($request)) {
            $source = $this->resolveSource($request, $importer);
            if ($source === null) {
                return response()->json([
                    'message' => 'Provide a valid YouTube or Google Drive link / id.',
                ], 422);
            }
            $validated = [...$validated, ...$source];
        }

        $video->update($validated);

        return response()->json($video->fresh()->load('course:id,name,slug'));
    }

    public function destroy(int $id): JsonResponse
    {
        VideoSolution::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'title'         => ['required', 'string', 'max:200'],
            'description'   => ['nullable', 'string'],
            'author'        => ['nullable', 'string', 'max:120'],
            'duration'      => ['nullable', 'string', 'max:20'],
            'thumbnail_url' => ['nullable', 'string', 'url', 'max:500'],
            'course_id'     => ['nullable', 'integer', 'exists:courses,id'],
            'chapters'      => ['nullable', 'array'],
            'is_pro'        => ['sometimes', 'boolean'],
            'is_published'  => ['sometimes', 'boolean'],
            'sort_order'    => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function hasSourceInput(Request $request): bool
    {
        return filled($request->input('link'))
            || filled($request->input('drive_file_id'))
            || filled($request->input('youtube_id'));
    }

    /**
     * @return array{provider: string, youtube_id: ?string, drive_file_id: ?string}|null
     */
    private function resolveSource(Request $request, VideoImporter $importer): ?array
    {
        return $importer->resolveSource(
            trim((string) $request->input('link', '')),
            strtolower(trim((string) $request->input('provider', ''))),
            $request->only(['youtube_id', 'drive_file_id']),
        );
    }
}
