<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminIDEController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE index scaffolded.']);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE store scaffolded.']);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE show scaffolded.', 'id' => $id]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE update scaffolded.', 'id' => $id]);
    }

    public function destroy(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE destroy scaffolded.', 'id' => $id]);
    }

    public function addTestCase(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE add test case scaffolded.', 'id' => $id]);
    }

    public function updateTestCase(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE update test case scaffolded.', 'id' => $id]);
    }

    public function deleteTestCase(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin IDE delete test case scaffolded.', 'id' => $id]);
    }
}
