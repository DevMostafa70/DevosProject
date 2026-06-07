<?php

use App\Http\Controllers\API\AnswerController;
use App\Http\Controllers\API\AntiCheatController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\InterviewAnalysisController;
use App\Http\Controllers\API\InterviewController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\ResultsController;
use App\Http\Controllers\API\ResumeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // User profile
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Profile Routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
        Route::post('/avatar', [ProfileController::class, 'uploadAvatar']);
    });

    // Results Routes
    Route::prefix('results')->group(function () {
        Route::get('/', [ResultsController::class, 'index']);
        Route::get('/summary', [ResultsController::class, 'summary']);
        Route::get('/{interview}', [ResultsController::class, 'show']);
        Route::delete('/{interview}', [ResultsController::class, 'destroy']);
    });

    // Dashboard Routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/progress', [DashboardController::class, 'progress']);
        Route::get('/weaknesses', [DashboardController::class, 'weaknesses']);
        Route::get('/daily-questions', [DashboardController::class, 'dailyQuestions']);
    });

    // Resume Routes
    Route::prefix('resume')->group(function () {
        Route::post('/upload', [ResumeController::class, 'upload']);
        Route::get('/', [ResumeController::class, 'index']);
        Route::get('/latest', [ResumeController::class, 'latest']);
        Route::get('/{resume}', [ResumeController::class, 'show']);
        Route::get('/{resume}/improvements', [ResumeController::class, 'improvements']);
        Route::delete('/{resume}', [ResumeController::class, 'destroy']);
    });

    // Interviews
    Route::apiResource('interviews', InterviewController::class)->except(['update', 'destroy']);

    Route::post('/interviews/{interview}/complete', [InterviewController::class, 'complete']);

    Route::get('/interviews/{interview}/status', [InterviewController::class, 'checkFinalStatus']);

    Route::get('/interviews/{interview}/report', [InterviewController::class, 'getFinalReport']);

    // Interview Answer AI Analysis
    Route::post('/analyze-answer', [InterviewAnalysisController::class, 'analyze']);

    // Answers
    Route::post('/answers', [AnswerController::class, 'store']);
    Route::get('/answers/{answer}', [AnswerController::class, 'show']);

    // Anti-cheat
    Route::post('/anti-cheat/violations', [AntiCheatController::class, 'store']);

    Route::get('/interviews/{interview}/violations', [AntiCheatController::class, 'index']);
    
 // report is ready
    Route::get('/interviews/{interview}/report-ready', [InterviewController::class, 'checkReportReady'])
    ->middleware('auth:sanctum');

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);
});
