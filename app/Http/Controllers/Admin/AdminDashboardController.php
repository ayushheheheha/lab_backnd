<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Course;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $recentAttempts = Attempt::query()
            ->where('is_complete', true)
            ->whereNotNull('submitted_at')
            ->with([
                'user:id,name',
                'quiz:id,course_id,title',
                'quiz.course:id,name',
            ])
            ->orderByDesc('submitted_at')
            ->limit(10)
            ->get()
            ->map(fn (Attempt $attempt) => [
                'student_name' => $attempt->user?->name,
                'quiz_title' => $attempt->quiz?->title,
                'course_name' => $attempt->quiz?->course?->name,
                'score' => (float) ($attempt->score ?? 0),
                'total_marks' => (float) ($attempt->total_marks ?? 0),
                'submitted_at' => $attempt->submitted_at,
            ])
            ->values();

        return response()->json([
            'total_students' => User::query()->where('is_admin', false)->count(),
            'total_quizzes' => Quiz::query()->count(),
            'total_questions' => Question::query()->count(),
            'total_courses' => Course::query()->count(),
            'total_attempts_today' => Attempt::query()
                ->whereDate('submitted_at', today())
                ->where('is_complete', true)
                ->count(),
            'recent_attempts' => $recentAttempts,
        ]);
    }
}
