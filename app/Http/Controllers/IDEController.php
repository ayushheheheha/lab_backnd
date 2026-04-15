<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IDEController extends Controller
{
    public function bySlug(string $slug): JsonResponse
    {
        return response()->json(['message' => 'IDE problems by course scaffolded.', 'slug' => $slug]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'IDE problem show scaffolded.', 'id' => $id]);
    }

    public function run(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'IDE run scaffolded.', 'id' => $id]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'IDE submit scaffolded.', 'id' => $id]);
    }

    public function mySubmissions(int $id): JsonResponse
    {
        return response()->json(['message' => 'IDE submissions scaffolded.', 'id' => $id]);
    }
}
