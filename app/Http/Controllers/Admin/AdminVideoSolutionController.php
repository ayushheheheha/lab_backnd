<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoSolution;
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:200'],
            'drive_file_id' => ['required', 'string', 'max:200'],
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

        $video = VideoSolution::query()->create($validated);

        return response()->json($video->load('course:id,name,slug'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $video = VideoSolution::query()->findOrFail($id);

        $validated = $request->validate([
            'title'         => ['sometimes', 'required', 'string', 'max:200'],
            'drive_file_id' => ['sometimes', 'required', 'string', 'max:200'],
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

        $video->update($validated);

        return response()->json($video->fresh()->load('course:id,name,slug'));
    }

    public function destroy(int $id): JsonResponse
    {
        VideoSolution::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
