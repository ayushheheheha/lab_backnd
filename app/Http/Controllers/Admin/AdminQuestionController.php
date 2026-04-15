<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminQuestionController extends Controller
{
    public function index(int $quizId): JsonResponse
    {
        return response()->json(['message' => 'Admin question index scaffolded.', 'quiz_id' => $quizId]);
    }

    public function store(Request $request, int $quizId): JsonResponse
    {
        return response()->json(['message' => 'Admin question store scaffolded.', 'quiz_id' => $quizId]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin question show scaffolded.', 'id' => $id]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin question update scaffolded.', 'id' => $id]);
    }

    public function destroy(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin question destroy scaffolded.', 'id' => $id]);
    }

    public function reorder(Request $request, int $quizId): JsonResponse
    {
        return response()->json(['message' => 'Admin question reorder scaffolded.', 'quiz_id' => $quizId]);
    }
}
