<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\DB;

class QuestionImporter
{
    public function normalizeMany(array $rows): array
    {
        $errors = [];
        $normalized = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = [
                    'row' => $index + 1,
                    'field' => 'question',
                    'message' => 'Each question must be a JSON object.',
                ];
                continue;
            }

            [$normalizedRow, $rowErrors] = $this->normalize($row, $index);

            if (! empty($rowErrors)) {
                $errors = [...$errors, ...$rowErrors];
                continue;
            }

            if ($normalizedRow) {
                $normalized[] = $normalizedRow;
            }
        }

        return ['rows' => $normalized, 'errors' => $errors];
    }

    public function persist(int $quizId, array $normalizedRows): array
    {
        $importedIds = [];

        DB::transaction(function () use ($quizId, $normalizedRows, &$importedIds) {
            $nextPosition = (int) Question::query()->where('quiz_id', $quizId)->max('position') + 1;

            foreach ($normalizedRows as $row) {
                $question = Question::query()->create([
                    'quiz_id' => $quizId,
                    'type' => $row['type'],
                    'stem' => $row['stem'],
                    'stem_image' => null,
                    'stem_code' => $row['stem_code'],
                    'stem_code_language' => $row['stem_code_language'],
                    'stem_table' => $row['stem_table'],
                    'explanation' => $row['explanation'],
                    'marks' => $row['marks'],
                    'difficulty' => $row['difficulty'],
                    'position' => $nextPosition++,
                    'numerical_answer' => $row['type'] === 'numerical' ? $row['numerical_answer'] : null,
                    'numerical_tolerance' => $row['type'] === 'numerical' ? $row['numerical_tolerance'] : 0.01,
                ]);

                $this->syncTypeSpecificAnswers(
                    $question,
                    $row['type'],
                    $row['options'],
                    $row['acceptable_answers']
                );

                $importedIds[] = $question->id;
            }
        });

        return $importedIds;
    }

    private function normalize(array $row, int $index): array
    {
        $errors = [];

        $typeMap = [
            'mcq_single' => 'mcq',
            'mcq_multi' => 'multi_select',
        ];
        $rawType = strtolower(trim((string) ($row['type'] ?? '')));
        $type = $typeMap[$rawType] ?? $rawType;

        if (! in_array($type, ['mcq', 'multi_select', 'true_false', 'short_answer', 'numerical', 'comprehension'], true)) {
            $errors[] = $this->importError($index, 'type', 'Unsupported question type.');
            return [null, $errors];
        }

        $promptParts = $this->extractPromptParts($row['prompt'] ?? []);
        $stem = trim((string) ($row['stem'] ?? $promptParts['text'] ?? ''));
        $stemCode = trim((string) ($row['stem_code'] ?? $promptParts['code'] ?? ''));
        $stemCodeLanguage = trim((string) ($row['stem_code_language'] ?? $promptParts['code_language'] ?? 'pseudocode'));
        $stemTable = $this->extractStemTable($row['stem_table'] ?? null);
        $explanation = $this->extractExplanationText($row['explanation'] ?? null);

        if ($stem === '' && $stemCode === '' && $stemTable === null) {
            $errors[] = $this->importError($index, 'stem', 'Question stem, prompt text, or stem_table is required.');
        }

        if ($type === 'comprehension') {
            $marks = 0.0;
        } else {
            $marks = isset($row['marks']) ? (float) $row['marks'] : 1.0;
            if ($marks <= 0) {
                $errors[] = $this->importError($index, 'marks', 'Marks must be greater than 0.');
                $marks = 1.0;
            }
        }

        $difficulty = strtolower(trim((string) ($row['difficulty'] ?? 'medium')));
        if (! in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $errors[] = $this->importError($index, 'difficulty', 'Difficulty must be easy, medium, or hard.');
            $difficulty = 'medium';
        }

        $options = [];
        $acceptableAnswers = [];
        $numericalAnswer = null;
        $numericalTolerance = isset($row['numerical_tolerance']) ? (float) $row['numerical_tolerance'] : 0.01;

        if (in_array($type, ['mcq', 'multi_select'], true)) {
            $options = $this->normalizeImportedOptions($row['options'] ?? []);

            if (count($options) < 2) {
                $errors[] = $this->importError($index, 'options', 'At least 2 options are required.');
            }

            if ($type === 'mcq') {
                $correctAnswer = $row['correct_answer'] ?? null;
                if (! is_int($correctAnswer) && ! ctype_digit((string) $correctAnswer)) {
                    $errors[] = $this->importError($index, 'correct_answer', 'correct_answer must be a valid option index.');
                } else {
                    $correctIndex = (int) $correctAnswer;
                    if (! array_key_exists($correctIndex, $options)) {
                        $errors[] = $this->importError($index, 'correct_answer', 'correct_answer is out of range.');
                    } else {
                        $options = array_map(fn ($item) => [...$item, 'is_correct' => false], $options);
                        $options[$correctIndex]['is_correct'] = true;
                    }
                }
            }

            if ($type === 'multi_select') {
                $hasPresetCorrect = collect($options)->contains(fn ($option) => (bool) ($option['is_correct'] ?? false));
                $correctAnswers = $row['correct_answers'] ?? null;

                if (is_array($correctAnswers) && ! empty($correctAnswers)) {
                    $options = array_map(fn ($item) => [...$item, 'is_correct' => false], $options);
                    $resolved = collect($correctAnswers)
                        ->map(fn ($item) => is_int($item) || ctype_digit((string) $item) ? (int) $item : null)
                        ->filter(fn ($value) => $value !== null)
                        ->unique()
                        ->values();

                    if ($resolved->isEmpty()) {
                        $errors[] = $this->importError($index, 'correct_answers', 'correct_answers must include at least one valid option index.');
                    } else {
                        foreach ($resolved as $answerIndex) {
                            if (! array_key_exists($answerIndex, $options)) {
                                $errors[] = $this->importError($index, 'correct_answers', 'One or more correct_answers indices are out of range.');
                                continue;
                            }
                            $options[$answerIndex]['is_correct'] = true;
                        }
                    }
                } elseif (! $hasPresetCorrect) {
                    $errors[] = $this->importError($index, 'correct_answers', 'Select at least one correct option.');
                }
            }
        }

        if ($type === 'true_false') {
            $correctRaw = $row['correct_answer'] ?? true;
            $trueIsCorrect = true;

            if (is_bool($correctRaw)) {
                $trueIsCorrect = $correctRaw;
            } elseif (is_int($correctRaw) || ctype_digit((string) $correctRaw)) {
                $trueIsCorrect = (int) $correctRaw === 0;
            } elseif (is_string($correctRaw)) {
                $normalized = strtolower(trim($correctRaw));
                if ($normalized === 'true') {
                    $trueIsCorrect = true;
                } elseif ($normalized === 'false') {
                    $trueIsCorrect = false;
                } else {
                    $errors[] = $this->importError($index, 'correct_answer', 'For true_false, use true/false or 0/1.');
                }
            } else {
                $errors[] = $this->importError($index, 'correct_answer', 'For true_false, use true/false or 0/1.');
            }

            $options = [
                [
                    'option_type' => 'text',
                    'option_text' => 'True',
                    'code_language' => null,
                    'is_correct' => $trueIsCorrect,
                    'position' => 0,
                ],
                [
                    'option_type' => 'text',
                    'option_text' => 'False',
                    'code_language' => null,
                    'is_correct' => ! $trueIsCorrect,
                    'position' => 1,
                ],
            ];
        }

        if ($type === 'short_answer') {
            $acceptableAnswers = collect($row['acceptable_answers'] ?? [])
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all();

            if (empty($acceptableAnswers)) {
                $errors[] = $this->importError($index, 'acceptable_answers', 'At least one acceptable answer is required.');
            }
        }

        if ($type === 'numerical') {
            if (! isset($row['numerical_answer']) || $row['numerical_answer'] === '') {
                $errors[] = $this->importError($index, 'numerical_answer', 'numerical_answer is required.');
            } elseif (! is_numeric($row['numerical_answer'])) {
                $errors[] = $this->importError($index, 'numerical_answer', 'numerical_answer must be numeric.');
            } else {
                $numericalAnswer = (float) $row['numerical_answer'];
            }

            if ($numericalTolerance < 0) {
                $errors[] = $this->importError($index, 'numerical_tolerance', 'numerical_tolerance cannot be negative.');
                $numericalTolerance = 0.01;
            }
        }

        if (! empty($errors)) {
            return [null, $errors];
        }

        return [[
            'type' => $type,
            'stem' => $stem !== '' ? $stem : 'Refer to code block.',
            'stem_code' => $stemCode !== '' ? $stemCode : null,
            'stem_code_language' => $stemCodeLanguage !== '' ? $stemCodeLanguage : 'pseudocode',
            'stem_table' => $stemTable,
            'explanation' => $explanation,
            'marks' => $marks,
            'difficulty' => $difficulty,
            'options' => $options,
            'acceptable_answers' => $acceptableAnswers,
            'numerical_answer' => $numericalAnswer,
            'numerical_tolerance' => $numericalTolerance,
        ], []];
    }

    private function normalizeImportedOptions(mixed $rawOptions): array
    {
        if (! is_array($rawOptions)) {
            return [];
        }

        return collect($rawOptions)
            ->map(function ($option, $index) {
                if (is_string($option)) {
                    return [
                        'option_type' => 'text',
                        'option_text' => trim($option),
                        'code_language' => null,
                        'is_correct' => false,
                        'position' => $index,
                    ];
                }

                if (! is_array($option)) {
                    return null;
                }

                $content = $this->extractOptionContent($option);
                $optionType = $content['code'] !== '' && $content['text'] === '' ? 'code' : 'text';
                $optionText = $optionType === 'code' ? $content['code'] : $content['text'];

                return [
                    'option_type' => $optionType,
                    'option_text' => $optionText,
                    'code_language' => $optionType === 'code' ? ($content['code_language'] ?: 'pseudocode') : null,
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'position' => (int) ($option['position'] ?? $index),
                ];
            })
            ->filter(fn ($option) => is_array($option) && trim((string) ($option['option_text'] ?? '')) !== '')
            ->values()
            ->all();
    }

    private function extractPromptParts(mixed $prompt): array
    {
        if (! is_array($prompt)) {
            return [
                'text' => '',
                'code' => '',
                'code_language' => null,
            ];
        }

        $textParts = [];
        $code = '';
        $codeLanguage = null;

        foreach ($prompt as $block) {
            if (! is_array($block)) {
                continue;
            }

            $kind = strtolower(trim((string) ($block['kind'] ?? 'text')));
            if ($kind === 'text') {
                $value = trim((string) ($block['value'] ?? ''));
                if ($value !== '') {
                    $textParts[] = $value;
                }
            }

            if ($kind === 'code' && $code === '') {
                $code = trim((string) ($block['value'] ?? ''));
                $codeLanguage = trim((string) ($block['language'] ?? 'pseudocode')) ?: 'pseudocode';
            }
        }

        return [
            'text' => trim(implode("\n\n", $textParts)),
            'code' => $code,
            'code_language' => $codeLanguage,
        ];
    }

    private function extractStemTable(mixed $rawTable): ?array
    {
        if (! is_array($rawTable)) {
            return null;
        }

        $headers = collect($rawTable['headers'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->values()
            ->all();

        $rows = collect($rawTable['rows'] ?? [])
            ->map(function ($row) {
                if (! is_array($row)) {
                    return [];
                }
                return array_values(array_map(fn ($cell) => (string) $cell, $row));
            })
            ->values()
            ->all();

        $hasContent = collect($headers)->contains(fn ($value) => $value !== '')
            || collect($rows)->contains(fn ($row) => collect($row)->contains(fn ($cell) => trim($cell) !== ''));

        if (! $hasContent) {
            return null;
        }

        return [
            'caption' => trim((string) ($rawTable['caption'] ?? '')),
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function extractExplanationText(mixed $rawExplanation): ?string
    {
        if (is_string($rawExplanation)) {
            $trimmed = trim($rawExplanation);
            return $trimmed !== '' ? $trimmed : null;
        }

        if (! is_array($rawExplanation)) {
            return null;
        }

        $parts = [];
        foreach ($rawExplanation as $block) {
            if (! is_array($block)) {
                continue;
            }

            $kind = strtolower(trim((string) ($block['kind'] ?? 'text')));
            $value = trim((string) ($block['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($kind === 'code') {
                $parts[] = $value;
                continue;
            }

            if ($kind === 'text') {
                $parts[] = $value;
            }
        }

        $joined = trim(implode("\n\n", $parts));
        return $joined !== '' ? $joined : null;
    }

    private function extractOptionContent(array $option): array
    {
        if (isset($option['option_text']) || isset($option['option_type'])) {
            $optionType = strtolower(trim((string) ($option['option_type'] ?? 'text')));
            return [
                'text' => $optionType === 'text' ? trim((string) ($option['option_text'] ?? '')) : '',
                'code' => $optionType === 'code' ? trim((string) ($option['option_text'] ?? '')) : '',
                'code_language' => trim((string) ($option['code_language'] ?? 'pseudocode')),
            ];
        }

        if (isset($option['text']) || isset($option['code'])) {
            return [
                'text' => trim((string) ($option['text'] ?? '')),
                'code' => trim((string) ($option['code'] ?? '')),
                'code_language' => trim((string) ($option['language'] ?? $option['code_language'] ?? 'pseudocode')),
            ];
        }

        if ($this->looksLikeBlocksArray($option)) {
            $textParts = [];
            $code = '';
            $codeLanguage = 'pseudocode';

            foreach ($option as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $kind = strtolower(trim((string) ($block['kind'] ?? 'text')));
                $value = trim((string) ($block['value'] ?? ''));

                if ($value === '') {
                    continue;
                }

                if ($kind === 'text') {
                    $textParts[] = $value;
                }

                if ($kind === 'code' && $code === '') {
                    $code = $value;
                    $codeLanguage = trim((string) ($block['language'] ?? 'pseudocode')) ?: 'pseudocode';
                }
            }

            return [
                'text' => trim(implode("\n\n", $textParts)),
                'code' => $code,
                'code_language' => $codeLanguage,
            ];
        }

        return [
            'text' => trim((string) ($option['value'] ?? '')),
            'code' => '',
            'code_language' => 'pseudocode',
        ];
    }

    private function looksLikeBlocksArray(array $value): bool
    {
        return ! empty($value) && is_array(reset($value)) && array_key_exists('kind', reset($value));
    }

    private function importError(int $rowIndex, string $field, string $message): array
    {
        return [
            'row' => $rowIndex + 1,
            'field' => $field,
            'message' => $message,
        ];
    }

    private function syncTypeSpecificAnswers(Question $question, string $type, array $options, array $acceptableAnswers): void
    {
        $question->questionOptions()->delete();
        $question->shortAnswerAcceptables()->delete();

        if (in_array($type, ['mcq', 'multi_select', 'true_false'], true)) {
            $optionRows = collect($options)
                ->map(function ($option, $index) {
                    return [
                        'question_id' => null,
                        'option_type' => in_array(($option['option_type'] ?? 'text'), ['text', 'code'], true) ? $option['option_type'] : 'text',
                        'option_text' => (string) ($option['option_text'] ?? ''),
                        'code_language' => $option['code_language'] ?? null,
                        'is_correct' => (bool) ($option['is_correct'] ?? false),
                        'position' => (int) ($option['position'] ?? $index),
                    ];
                })
                ->filter(fn ($option) => $option['option_text'] !== '')
                ->values();

            if ($type === 'true_false' && $optionRows->isEmpty()) {
                $optionRows = collect([
                    [
                        'question_id' => null,
                        'option_type' => 'text',
                        'option_text' => 'True',
                        'code_language' => null,
                        'is_correct' => true,
                        'position' => 0,
                    ],
                    [
                        'question_id' => null,
                        'option_type' => 'text',
                        'option_text' => 'False',
                        'code_language' => null,
                        'is_correct' => false,
                        'position' => 1,
                    ],
                ]);
            }

            foreach ($optionRows as $row) {
                $question->questionOptions()->create([
                    ...$row,
                    'question_id' => $question->id,
                ]);
            }

            if ($type === 'mcq' || $type === 'true_false') {
                $hasCorrect = $question->questionOptions()->where('is_correct', true)->exists();
                if (! $hasCorrect) {
                    $firstOption = $question->questionOptions()->orderBy('position')->first();
                    if ($firstOption) {
                        $firstOption->update(['is_correct' => true]);
                    }
                }
            }

            return;
        }

        if ($type === 'short_answer') {
            collect($acceptableAnswers)
                ->map(fn ($text) => trim((string) $text))
                ->filter()
                ->values()
                ->each(fn ($text) => $question->shortAnswerAcceptables()->create([
                    'acceptable_text' => $text,
                ]));
        }
    }
}
