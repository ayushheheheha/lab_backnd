<?php

namespace App\Http\Controllers;

use App\Models\VideoSolution;
use Illuminate\Http\JsonResponse;

class VideoSolutionController extends Controller
{
    public function index(): JsonResponse
    {
        $videos = VideoSolution::query()
            ->where('is_published', true)
            ->with('course:id,name,slug')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id', 'course_id', 'title', 'drive_file_id', 'description',
                'author', 'duration', 'thumbnail_url', 'chapters', 'is_pro', 'sort_order',
            ]);

        return response()->json($videos);
    }
}
