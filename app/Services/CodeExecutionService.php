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
        if (! $this->shouldFallbackToLocal($remote)) {
            return $remote;
        }

        return $this->executeLocally($language, $code, $input, $timeLimit);
    }

    private function shouldFallbackToLocal(array $remote): bool
    {
        $stderr = trim((string) ($remote['stderr'] ?? ''));
        if ($stderr === 'Execution service unavailable') {
            return true;
        }

        $stdout = trim((string) ($remote['stdout'] ?? ''));
        $code = (int) ($remote['exit_code'] ?? 1);

        // Some remote environments can return crash exit codes without any output.
        if ($stdout === '' && $stderr === '' && $code !== 0) {
            return true;
        }

        return false;
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
        $className = $this->extractJavaMainClassName($code) ?? 'Solution';
        $fileName = $className.'.java';
        $javacBin = (string) env('JAVAC_BIN', 'javac');
        $javaBin = (string) env('JAVA_BIN', 'java');

        File::put($workDir.'/'.$fileName, $code);

        $compile = Process::path($workDir)
            ->timeout($timeLimit + 5)
            ->run([$javacBin, $fileName]);

        if (! $compile->successful()) {
            return [
                'stdout' => $compile->output(),
                'stderr' => $this->normalizeProcessError(
                    $compile->errorOutput(),
                    $compile->exitCode(),
                    'Java compilation failed'
                ),
                'exit_code' => $compile->exitCode() ?? 1,
            ];
        }

        $run = Process::path($workDir)
            ->timeout($timeLimit + 2)
            ->input($input)
            ->run([$javaBin, '-cp', $workDir, $className]);

        // Retry using plain invocation for environments where -cp with absolute path behaves oddly.
        if (! $run->successful() && trim($run->output()) === '' && trim($run->errorOutput()) === '') {
            $run = Process::path($workDir)
                ->timeout($timeLimit + 2)
                ->input($input)
                ->run([$javaBin, $className]);
        }

        return [
            'stdout' => $run->output(),
            'stderr' => $this->normalizeProcessError(
                $run->errorOutput(),
                $run->exitCode(),
                'Java runtime failed'
            ),
            'exit_code' => $run->exitCode() ?? 1,
        ];
    }

    private function normalizeProcessError(?string $stderr, ?int $exitCode, string $context): string
    {
        $errorText = trim((string) $stderr);
        $code = (int) ($exitCode ?? 1);

        if ($errorText !== '') {
            return $errorText;
        }

        if ($code !== 0) {
            return $context.' (exit code '.$code.'). Check JAVA_BIN/JAVAC_BIN in .env if this persists.';
        }

        return '';
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

    private function extractJavaMainClassName(string $code): ?string
    {
        if (preg_match('/public\s+class\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
