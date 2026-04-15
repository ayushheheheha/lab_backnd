<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class CodeExecutionService
{
    public function execute(string $language, string $code, string $input = '', int $timeLimit = 5): array
    {
        $executor = strtolower((string) env('CODE_EXECUTOR', 'local'));
        if ($executor === 'local') {
            return $this->executeLocally($language, $code, $input, $timeLimit);
        }

        $remote = $this->executeViaPiston($language, $code, $input, $timeLimit);
        if (($remote['stderr'] ?? '') !== 'Execution service unavailable') {
            return $remote;
        }

        return $this->executeLocally($language, $code, $input, $timeLimit);
    }

    private function executeViaPiston(string $language, string $code, string $input, int $timeLimit): array
    {
        $langMap = [
            'python' => ['language' => 'python', 'version' => '3.10.0'],
            'java' => ['language' => 'java', 'version' => '15.0.2'],
            'cpp' => ['language' => 'c++', 'version' => '10.2.0'],
            'c++' => ['language' => 'c++', 'version' => '10.2.0'],
        ];

        $lang = $langMap[$language] ?? $langMap['python'];

        try {
            $response = Http::timeout($timeLimit + 5)->post(rtrim((string) env('PISTON_API_URL', config('services.piston.url')), '/').'/execute', [
                'language' => $lang['language'],
                'version' => $lang['version'],
                'files' => [['content' => $code]],
                'stdin' => $input,
                'run_timeout' => $timeLimit * 1000,
            ]);
        } catch (\Throwable) {
            return [
                'stdout' => '',
                'stderr' => 'Execution service unavailable',
                'exit_code' => 1,
            ];
        }

        if ($response->failed()) {
            return [
                'stdout' => '',
                'stderr' => 'Execution service unavailable',
                'exit_code' => 1,
            ];
        }

        $run = $response->json('run');

        return [
            'stdout' => $run['stdout'] ?? '',
            'stderr' => $run['stderr'] ?? '',
            'exit_code' => $run['code'] ?? 0,
        ];
    }

    private function executeLocally(string $language, string $code, string $input, int $timeLimit): array
    {
        $lang = strtolower($language);
        $workDir = storage_path('app/code-runner/'.Str::uuid()->toString());
        File::ensureDirectoryExists($workDir);

        try {
            return match ($lang) {
                'python' => $this->runPython($workDir, $code, $input, $timeLimit),
                'java' => $this->runJava($workDir, $code, $input, $timeLimit),
                'cpp', 'c++' => $this->runCpp($workDir, $code, $input, $timeLimit),
                default => [
                    'stdout' => '',
                    'stderr' => 'Unsupported language for local executor',
                    'exit_code' => 1,
                ],
            };
        } catch (\Throwable) {
            return [
                'stdout' => '',
                'stderr' => 'Local execution failed',
                'exit_code' => 1,
            ];
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function runPython(string $workDir, string $code, string $input, int $timeLimit): array
    {
        File::put($workDir.'/solution.py', $code);

        $run = Process::path($workDir)
            ->timeout($timeLimit + 2)
            ->input($input)
            ->run(['python', 'solution.py']);

        return [
            'stdout' => $run->output(),
            'stderr' => $run->errorOutput(),
            'exit_code' => $run->exitCode() ?? 1,
        ];
    }

    private function runJava(string $workDir, string $code, string $input, int $timeLimit): array
    {
        File::put($workDir.'/Solution.java', $code);

        $compile = Process::path($workDir)
            ->timeout($timeLimit + 5)
            ->run(['javac', 'Solution.java']);

        if (! $compile->successful()) {
            return [
                'stdout' => $compile->output(),
                'stderr' => $compile->errorOutput(),
                'exit_code' => $compile->exitCode() ?? 1,
            ];
        }

        $run = Process::path($workDir)
            ->timeout($timeLimit + 2)
            ->input($input)
            ->run(['java', 'Solution']);

        return [
            'stdout' => $run->output(),
            'stderr' => $run->errorOutput(),
            'exit_code' => $run->exitCode() ?? 1,
        ];
    }

    private function runCpp(string $workDir, string $code, string $input, int $timeLimit): array
    {
        File::put($workDir.'/main.cpp', $code);

        $compile = Process::path($workDir)
            ->timeout($timeLimit + 5)
            ->run(['g++', 'main.cpp', '-std=c++17', '-O2', '-o', 'main.exe']);

        if (! $compile->successful()) {
            return [
                'stdout' => $compile->output(),
                'stderr' => $compile->errorOutput(),
                'exit_code' => $compile->exitCode() ?? 1,
            ];
        }

        $run = Process::path($workDir)
            ->timeout($timeLimit + 2)
            ->input($input)
            ->run(['main.exe']);

        return [
            'stdout' => $run->output(),
            'stderr' => $run->errorOutput(),
            'exit_code' => $run->exitCode() ?? 1,
        ];
    }
}
