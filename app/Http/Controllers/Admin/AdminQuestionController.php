<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\ShortAnswerAcceptable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminQuestionController extends Controller
{
    public function index(int $quizId): JsonResponse
    {
        Quiz::query()->findOrFail($quizId);

        $questions = Question::query()
            ->where('quiz_id', $quizId)
            ->with([
                'questionOptions' => fn ($query) => $query->orderBy('position'),
                'shortAnswerAcceptables',
            ])
            ->orderBy('position')
            ->get();

        return response()->json($questions);
    }

    public function store(Request $request, int $quizId): JsonResponse
    {
        Quiz::query()->findOrFail($quizId);

        $validated = $this->validateQuestionPayload($request, true);
        $options = $this->parseOptions($request);
        $acceptableAnswers = $this->parseAcceptableAnswers($request);

        $created = DB::transaction(function () use ($validated, $request, $quizId, $options, $acceptableAnswers) {
            $stemImageUrl = null;
            if ($request->hasFile('stem_image')) {
                $path = $request->file('stem_image')->store('question-images', 'public');
                $stemImageUrl = Storage::url($path);
            }

            $position = (int) Question::query()->where('quiz_id', $quizId)->max('position') + 1;

            $question = Question::query()->create([
                'quiz_id' => $quizId,
                'type' => $validated['type'],
                'stem' => $validated['stem'],
                'stem_image' => $stemImageUrl,
                'stem_code' => $validated['stem_code'] ?? null,
                'stem_code_language' => $validated['stem_code_language'] ?? null,
                'explanation' => $validated['explanation'] ?? null,
                'marks' => $validated['marks'] ?? 1,
                'difficulty' => $validated['difficulty'] ?? 'medium',
                'position' => $position,
                'numerical_answer' => $validated['type'] === 'numerical' ? ($validated['numerical_answer'] ?? null) : null,
                'numerical_tolerance' => $validated['type'] === 'numerical' ? ($validated['numerical_tolerance'] ?? 0.01) : 0.01,
            ]);

            $this->syncTypeSpecificAnswers($question, $validated['type'], $options, $acceptableAnswers);

            return $question;
        });

        return response()->json(
            $created->load([
                'questionOptions' => fn ($query) => $query->orderBy('position'),
                'shortAnswerAcceptables',
            ]),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'Admin question show scaffolded.', 'id' => $id]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $question = Question::query()->with(['questionOptions', 'shortAnswerAcceptables'])->findOrFail($id);
        $validated = $this->validateQuestionPayload($request, false);
        $options = $this->parseOptions($request);
        $acceptableAnswers = $this->parseAcceptableAnswers($request);

        $updated = DB::transaction(function () use ($question, $validated, $request, $options, $acceptableAnswers) {
            $payload = Arr::except($validated, ['options', 'acceptable_answers']);

            if ($request->boolean('remove_stem_image') && $question->stem_image) {
                $this->deleteStemImage($question->stem_image);
                $payload['stem_image'] = null;
            }

            if ($request->hasFile('stem_image')) {
                if ($question->stem_image) {
                    $this->deleteStemImage($question->stem_image);
                }
                $path = $request->file('stem_image')->store('question-images', 'public');
                $payload['stem_image'] = Storage::url($path);
            }

            if (($payload['type'] ?? $question->type) !== 'numerical') {
                $payload['numerical_answer'] = null;
                $payload['numerical_tolerance'] = 0.01;
            }

            $question->update($payload);

            $this->syncTypeSpecificAnswers(
                $question,
                $payload['type'] ?? $question->type,
                $options,
                $acceptableAnswers
            );

            return $question;
        });

        return response()->json($updated->fresh()->load([
            'questionOptions' => fn ($query) => $query->orderBy('position'),
            'shortAnswerAcceptables',
        ]));
    }

    public function destroy(int $id): JsonResponse
    {
        $question = Question::query()->findOrFail($id);
        $question->delete();

        return response()->json([
            'message' => 'Question deleted successfully.',
        ]);
    }

    public function reorder(Request $request, int $quizId): JsonResponse
    {
        Quiz::query()->findOrFail($quizId);

        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['required', 'integer', 'exists:questions,id'],
        ]);

        DB::transaction(function () use ($validated, $quizId) {
            foreach ($validated['question_ids'] as $index => $questionId) {
                Question::query()
                    ->where('id', $questionId)
                    ->where('quiz_id', $quizId)
                    ->update(['position' => $index]);
            }
        });

        return response()->json([
            'message' => 'Question order updated successfully.',
        ]);
    }

    public function importJson(Request $request, int $quizId): JsonResponse
    {
        Quiz::query()->findOrFail($quizId);

        $validated = $request->validate([
            'schema_version' => ['nullable', 'string', 'max:20'],
            'questions' => ['required', 'array', 'min:1'],
            'allow_partial' => ['nullable', 'boolean'],
        ]);

        $allowPartial = $request->boolean('allow_partial', true);
        $rows = $validated['questions'];
        $errors = [];
        $normalizedRows = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = [
                    'row' => $index + 1,
                    'field' => 'question',
                    'message' => 'Each question must be a JSON object.',
                ];
                continue;
            }

            [$normalized, $rowErrors] = $this->normalizeImportedQuestion($row, $index);

            if (! empty($rowErrors)) {
                $errors = [...$errors, ...$rowErrors];
                continue;
            }

            if ($normalized) {
                $normalizedRows[] = $normalized;
            }
        }

        if (! $allowPartial && ! empty($errors)) {
            return response()->json([
                'message' => 'Import validation failed. Fix errors and retry.',
                'imported_count' => 0,
                'errors' => $errors,
            ], 422);
        }

        if (empty($normalizedRows)) {
            return response()->json([
                'message' => 'No valid questions found to import.',
                'imported_count' => 0,
                'errors' => $errors,
            ], 422);
        }

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

        return response()->json([
            'message' => empty($errors)
                ? 'Questions imported successfully.'
                : 'Questions imported with some validation errors.',
            'imported_count' => count($importedIds),
            'imported_ids' => $importedIds,
            'errors' => $errors,
        ]);
    }

    private function normalizeImportedQuestion(array $row, int $index): array
    {
        $errors = [];

        $typeMap = [
            'mcq_single' => 'mcq',
            'mcq_multi' => 'multi_select',
        ];
        $rawType = strtolower(trim((string) ($row['type'] ?? '')));
        $type = $typeMap[$rawType] ?? $rawType;

        if (! in_array($type, ['mcq', 'multi_select', 'true_false', 'short_answer', 'numerical'], true)) {
            $errors[] = $this->importError($index, 'type', 'Unsupported question type.');
            return [null, $errors];
        }

        $promptParts = $this->extractPromptParts($row['prompt'] ?? []);
        $stem = trim((string) ($row['stem'] ?? $promptParts['text'] ?? ''));
        $stemCode = trim((string) ($row['stem_code'] ?? $promptParts['code'] ?? ''));
        $stemCodeLanguage = trim((string) ($row['stem_code_language'] ?? $promptParts['code_language'] ?? 'pseudocode'));
        $explanation = $this->extractExplanationText($row['explanation'] ?? null);

        if ($stem === '' && $stemCode === '') {
            $errors[] = $this->importError($index, 'stem', 'Question stem or prompt text is required.');
        }

        $marks = isset($row['marks']) ? (float) $row['marks'] : 1.0;
        if ($marks <= 0) {
            $errors[] = $this->importError($index, 'marks', 'Marks must be greater than 0.');
            $marks = 1.0;
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

    private function validateQuestionPayload(Request $request, bool $isCreate): array
    {
        $baseRules = [
            'type' => [$isCreate ? 'required' : 'sometimes', 'string', Rule::in(['mcq', 'multi_select', 'true_false', 'short_answer', 'numerical'])],
            'stem' => [$isCreate ? 'required' : 'sometimes', 'string'],
            'stem_image' => ['nullable', 'image', 'max:4096'],
            'stem_code' => ['nullable', 'string'],
            'stem_code_language' => ['nullable', 'string', 'max:40'],
            'explanation' => ['nullable', 'string'],
            'marks' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard'])],
            'numerical_answer' => ['nullable', 'numeric'],
            'numerical_tolerance' => ['nullable', 'numeric', 'min:0'],
            'remove_stem_image' => ['nullable', 'boolean'],
        ];

        return $request->validate($baseRules);
    }

    private function parseOptions(Request $request): array
    {
        $options = $request->input('options', []);
        if (is_string($options) && $options !== '') {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }

        $optionsJson = $request->input('options_json');
        if (is_string($optionsJson) && $optionsJson !== '') {
            $decoded = json_decode($optionsJson, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        return is_array($options) ? $options : [];
    }

    private function parseAcceptableAnswers(Request $request): array
    {
        $answers = $request->input('acceptable_answers', []);
        if (is_string($answers) && $answers !== '') {
            $decoded = json_decode($answers, true);
            $answers = is_array($decoded) ? $decoded : [];
        }

        $answersJson = $request->input('acceptable_answers_json');
        if (is_string($answersJson) && $answersJson !== '') {
            $decoded = json_decode($answersJson, true);
            if (is_array($decoded)) {
                $answers = $decoded;
            }
        }

        return is_array($answers) ? $answers : [];
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

    private function deleteStemImage(string $stemImageUrl): void
    {
        $path = ltrim(str_replace('/storage/', '', $stemImageUrl), '/');
        if ($path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}
