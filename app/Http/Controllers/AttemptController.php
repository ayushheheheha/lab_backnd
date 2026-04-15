<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\Quiz;
use App\Services\AutoScoreService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttemptController extends Controller
{
    public function __construct(private readonly AutoScoreService $autoScoreService)
    {
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::query()->findOrFail($id);

        $attempt = Attempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $request->user()->id,
            'started_at' => now(),
            'is_complete' => false,
        ]);

        return response()->json([
            'attempt_id' => $attempt->id,
        ], 201);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $attempt = Attempt::query()
            ->with([
                'quiz.questions' => fn ($query) => $query
                    ->orderBy('position')
                    ->with(['questionOptions', 'shortAnswerAcceptables']),
            ])
            ->findOrFail($id);

        if ((int) $attempt->user_id !== (int) $request->user()->id) {
            throw new HttpResponseException(response()->json([
                'error' => 'Forbidden',
            ], 403));
        }

        if ($attempt->is_complete || $attempt->submitted_at) {
            return response()->json([
                'error' => 'This attempt has already been submitted',
            ], 422);
        }

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.selected_option_ids' => ['nullable', 'array'],
            'answers.*.text_answer' => ['nullable', 'string'],
            'answers.*.numerical_answer' => ['nullable', 'numeric'],
        ]);

        $quiz = $attempt->quiz;
        $questionsById = $quiz->questions->keyBy('id');
        $totalMarks = (float) $quiz->questions->sum(fn ($q) => (float) $q->marks);
        $sumAwarded = 0;

        DB::transaction(function () use ($attempt, $validated, $questionsById, &$sumAwarded) {
            foreach ($validated['answers'] as $answerData) {
                $question = $questionsById->get((int) $answerData['question_id']);
                if (! $question) {
                    continue;
                }

                $score = $this->autoScoreService->scoreAnswer($question, $answerData);
                $sumAwarded += (float) $score['marks_awarded'];

                AttemptAnswer::query()->updateOrCreate(
                    [
                        'attempt_id' => $attempt->id,
                        'question_id' => $question->id,
                    ],
                    [
                        'selected_option_ids' => $answerData['selected_option_ids'] ?? null,
                        'text_answer' => $answerData['text_answer'] ?? null,
                        'numerical_answer' => $answerData['numerical_answer'] ?? null,
                        'is_correct' => $score['is_correct'],
                        'marks_awarded' => $score['marks_awarded'],
                    ]
                );
            }

            $attempt->update([
                'submitted_at' => now(),
                'is_complete' => true,
                'score' => round($sumAwarded, 2),
                'total_marks' => round((float) $attempt->quiz->questions->sum(fn ($q) => (float) $q->marks), 2),
            ]);
        });

        $score = (float) $attempt->fresh()->score;
        $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;

        return response()->json([
            'attempt_id' => $attempt->id,
            'score' => $score,
            'total_marks' => $totalMarks,
            'percentage' => $percentage,
        ]);
    }

    public function result(Request $request, int $id): JsonResponse
    {
        $attempt = Attempt::query()
            ->with([
                'quiz.course:id,name',
                'quiz.questions' => fn ($query) => $query
                    ->orderBy('position')
                    ->with(['questionOptions' => fn ($optionQuery) => $optionQuery->orderBy('position'), 'shortAnswerAcceptables']),
                'attemptAnswers',
            ])
            ->findOrFail($id);

        if ((int) $attempt->user_id !== (int) $request->user()->id) {
            return response()->json([
                'error' => 'Forbidden',
            ], 403);
        }

        $score = (float) ($attempt->score ?? 0);
        $totalMarks = (float) ($attempt->total_marks ?? 0);
        $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;

        $answersByQuestion = $attempt->attemptAnswers->keyBy('question_id');

        $answers = $attempt->quiz->questions->map(function ($question) use ($answersByQuestion) {
            $studentAnswer = $answersByQuestion->get($question->id);
            return [
                'question_id' => $question->id,
                'question_stem' => $question->stem,
                'question_type' => $question->type,
                'stem_image' => $question->stem_image,
                'stem_code' => $question->stem_code,
                'stem_code_language' => $question->stem_code_language,
                'marks' => (float) $question->marks,
                'marks_awarded' => (float) ($studentAnswer?->marks_awarded ?? 0),
                'is_correct' => (bool) ($studentAnswer?->is_correct ?? false),
                'student_selected_option_ids' => $studentAnswer?->selected_option_ids,
                'student_text_answer' => $studentAnswer?->text_answer,
                'student_numerical_answer' => $studentAnswer?->numerical_answer,
                'options' => $question->questionOptions->map(fn ($option) => [
                    'id' => $option->id,
                    'option_type' => $option->option_type,
                    'option_text' => $option->option_text,
                    'code_language' => $option->code_language,
                    'is_correct' => (bool) $option->is_correct,
                    'position' => $option->position,
                ])->values(),
                'correct_short_answers' => $question->shortAnswerAcceptables->pluck('acceptable_text')->values(),
                'correct_numerical' => $question->numerical_answer,
                'explanation' => $question->explanation,
            ];
        })->values();

        return response()->json([
            'attempt' => [
                'id' => $attempt->id,
                'score' => $score,
                'total_marks' => $totalMarks,
                'percentage' => $percentage,
                'submitted_at' => $attempt->submitted_at,
            ],
            'quiz' => [
                'id' => $attempt->quiz?->id,
                'title' => $attempt->quiz?->title,
                'course_name' => $attempt->quiz?->course?->name,
            ],
            'answers' => $answers,
        ]);
    }
}
