<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminQuizController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Admin quiz index scaffolded.']);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Admin quiz store scaffolded.']);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin quiz show scaffolded.', 'id' => $id]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin quiz update scaffolded.', 'id' => $id]);
    }

    public function destroy(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin quiz destroy scaffolded.', 'id' => $id]);
    }

    public function toggle(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin quiz toggle scaffolded.', 'id' => $id]);
    }
}
