<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProgressController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Student progress scaffolded.']);
    }

    public function profile(): JsonResponse
    {
        return response()->json(['message' => 'Student profile scaffolded.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Student profile update scaffolded.']);
    }
}
