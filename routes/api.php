<?php

use App\Http\Controllers\Admin\AdminCourseController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminIDEController;
use App\Http\Controllers\Admin\AdminQuestionController;
use App\Http\Controllers\Admin\AdminQuizController;
use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\IDEController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\StudentProgressController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/resend-otp', [AuthController::class, 'resendOtp']);
Route::get('/auth/google', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);

// Authenticated student routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Courses
    Route::get('/courses', [CourseController::class, 'index']);
    Route::get('/courses/{slug}', [CourseController::class, 'show']);
    Route::get('/courses/{slug}/weeks', [CourseController::class, 'weeks']);
    Route::get('/courses/{slug}/weeks/{weekNumber}/quizzes', [CourseController::class, 'weekQuizzes']);
    Route::get('/courses/{slug}/exam-prep', [CourseController::class, 'examPrep']);
    Route::get('/courses/{slug}/ide-problems', [IDEController::class, 'bySlug']);

    // Quizzes and attempts
    Route::get('/quizzes/{id}', [QuizController::class, 'show']);
    Route::post('/quizzes/{id}/attempts', [AttemptController::class, 'start']);
    Route::post('/attempts/{id}/submit', [AttemptController::class, 'submit']);
    Route::get('/attempts/{id}/result', [AttemptController::class, 'result']);
    Route::get('/quizzes/{id}/leaderboard', [QuizController::class, 'leaderboard']);

    // IDE
    Route::get('/ide-problems/{id}', [IDEController::class, 'show']);
    Route::post('/ide-problems/{id}/run', [IDEController::class, 'run']);
    Route::post('/ide-problems/{id}/submit', [IDEController::class, 'submit']);
    Route::get('/ide-problems/{id}/my-submissions', [IDEController::class, 'mySubmissions']);

    // Student progress
    Route::get('/student/progress', [StudentProgressController::class, 'index']);
    Route::get('/student/profile', [StudentProgressController::class, 'profile']);
    Route::patch('/student/profile', [StudentProgressController::class, 'updateProfile']);
});

// Admin routes
Route::middleware(['auth:sanctum', 'is_admin'])->prefix('admin')->group(function () {
    Route::get('/stats', [AdminDashboardController::class, 'stats']);

    // Course management
    Route::get('/courses', [AdminCourseController::class, 'index']);
    Route::post('/courses', [AdminCourseController::class, 'store']);
    Route::put('/courses/{id}', [AdminCourseController::class, 'update']);
    Route::patch('/courses/{id}/toggle', [AdminCourseController::class, 'toggle']);

    // Quiz management
    Route::get('/quizzes', [AdminQuizController::class, 'index']);
    Route::post('/quizzes', [AdminQuizController::class, 'store']);
    Route::get('/quizzes/{id}', [AdminQuizController::class, 'show']);
    Route::put('/quizzes/{id}', [AdminQuizController::class, 'update']);
    Route::delete('/quizzes/{id}', [AdminQuizController::class, 'destroy']);
    Route::patch('/quizzes/{id}/toggle', [AdminQuizController::class, 'toggle']);

    // Question management (inside a quiz)
    Route::get('/quizzes/{quizId}/questions', [AdminQuestionController::class, 'index']);
    Route::post('/quizzes/{quizId}/questions', [AdminQuestionController::class, 'store']);
    Route::get('/questions/{id}', [AdminQuestionController::class, 'show']);
    Route::put('/questions/{id}', [AdminQuestionController::class, 'update']);
    Route::delete('/questions/{id}', [AdminQuestionController::class, 'destroy']);
    Route::patch('/quizzes/{quizId}/questions/reorder', [AdminQuestionController::class, 'reorder']);

    // IDE problem management
    Route::get('/ide-problems', [AdminIDEController::class, 'index']);
    Route::post('/ide-problems', [AdminIDEController::class, 'store']);
    Route::get('/ide-problems/{id}', [AdminIDEController::class, 'show']);
    Route::put('/ide-problems/{id}', [AdminIDEController::class, 'update']);
    Route::delete('/ide-problems/{id}', [AdminIDEController::class, 'destroy']);
    Route::post('/ide-problems/{id}/test-cases', [AdminIDEController::class, 'addTestCase']);
    Route::put('/test-cases/{id}', [AdminIDEController::class, 'updateTestCase']);
    Route::delete('/test-cases/{id}', [AdminIDEController::class, 'deleteTestCase']);
});
