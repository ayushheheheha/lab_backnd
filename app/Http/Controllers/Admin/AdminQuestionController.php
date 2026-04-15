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
