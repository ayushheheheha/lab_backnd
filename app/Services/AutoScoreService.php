<?php

namespace App\Services;

use App\Models\Question;

class AutoScoreService
{
    public function scoreAnswer(Question $question, array $answerData): array
    {
        $isCorrect = false;
        $marksAwarded = 0;

        switch ($question->type) {
            case 'mcq':
            case 'true_false':
                $correctOption = $question->options->where('is_correct', true)->first();
                $selected = $answerData['selected_option_ids'][0] ?? null;
                $isCorrect = $correctOption && (int) $selected === (int) $correctOption->id;
                $marksAwarded = $isCorrect ? (float) $question->marks : 0;
                break;

            case 'multi_select':
                $correctIds = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->toArray();
                $selectedIds = collect($answerData['selected_option_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->toArray();
                $isCorrect = $correctIds === $selectedIds;

                if ($isCorrect) {
                    $marksAwarded = (float) $question->marks;
                } elseif (! empty($selectedIds)) {
                    $selectedCorrect = array_intersect($selectedIds, $correctIds);
                    $selectedWrong = array_diff($selectedIds, $correctIds);
                    if (empty($selectedWrong) && ! empty($selectedCorrect)) {
                        $marksAwarded = round((float) $question->marks * 0.5, 2);
                    }
                }
                break;

            case 'short_answer':
                $acceptables = $question->shortAnswerAcceptables->pluck('acceptable_text')->toArray();
                $studentAnswer = trim(strtolower((string) ($answerData['text_answer'] ?? '')));

                foreach ($acceptables as $acceptable) {
                    if ($studentAnswer === trim(strtolower((string) $acceptable))) {
                        $isCorrect = true;
                        $marksAwarded = (float) $question->marks;
                        break;
                    }
                }
                break;

            case 'numerical':
                $studentVal = (float) ($answerData['numerical_answer'] ?? 0);
                $correctVal = (float) $question->numerical_answer;
                $tolerance = (float) $question->numerical_tolerance;
                $isCorrect = abs($studentVal - $correctVal) <= $tolerance;
                $marksAwarded = $isCorrect ? (float) $question->marks : 0;
                break;
        }

        return [
            'is_correct' => $isCorrect,
            'marks_awarded' => $marksAwarded,
        ];
    }
}
