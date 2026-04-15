<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCourseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Admin course index scaffolded.']);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Admin course store scaffolded.']);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin course update scaffolded.', 'id' => $id]);
    }

    public function toggle(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin course toggle scaffolded.', 'id' => $id]);
    }
}
