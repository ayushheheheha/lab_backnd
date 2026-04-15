<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CodeExecutionService
{
    public function run(string $language, string $code, string $stdin = ''): array
    {
        $response = Http::post(config('services.piston.url', env('PISTON_API_URL')), [
            'language' => $language,
            'version' => '*',
            'files' => [
                ['content' => $code],
            ],
            'stdin' => $stdin,
        ]);

        return $response->json() ?? [];
    }
}
