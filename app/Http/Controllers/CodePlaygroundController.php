<?php

namespace App\Http\Controllers;

use App\Services\CodeExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodePlaygroundController extends Controller
{
    public function __construct(private readonly CodeExecutionService $executionService)
    {
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'language' => ['required', 'in:python,java,cpp,c++'],
            'code' => ['required', 'string'],
            'stdin' => ['nullable', 'string'],
            'time_limit_seconds' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $start = microtime(true);

        $result = $this->executionService->execute(
            $validated['language'],
            $validated['code'],
            (string) ($validated['stdin'] ?? ''),
            (int) ($validated['time_limit_seconds'] ?? 5)
        );

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        return response()->json([
            'stdout' => (string) ($result['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? ''),
            'exit_code' => (int) ($result['exit_code'] ?? 1),
            'elapsed_ms' => $elapsedMs,
        ]);
    }
}
