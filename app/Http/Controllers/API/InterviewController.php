<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInterviewRequest;
use App\Http\Resources\InterviewResource;
use App\Http\Resources\QuestionResource;
use App\Http\Resources\FinalReportResource;
use App\Models\Interview;
use App\Models\Question;
use App\Models\FinalReport;
use App\Services\LLMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;


class InterviewController extends Controller
{
    protected LLMService $llmService;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
        // $this->middleware('auth:sanctum');
    }

    /**
     * Start a new interview
     */
    public function store(Request $request)
    {


        try {
            // Create interview
            $interview = Interview::create([
                'user_id' => Auth::id(),
                'position' => $request->position,
                'experience_level' => $request->experience_level,
                'difficulty' => $request->difficulty,
                'skills' => $request->skills,
                'number_of_questions' => $request->number_of_questions ?? 5,
                'status' => Interview::STATUS_PENDING,
            ]);

            // Generate questions using AI
            $questionsData = $this->llmService->generateQuestions($interview);

            // Save questions

            foreach ($questionsData as $questionData) {
                Question::create(array_merge($questionData, [
                    'interview_id' => $interview->id,
                    'status' => Question::STATUS_PENDING,
                ]));
            }

            // Update interview status
            $interview->update([
                'status' => Interview::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Interview started successfully',
                'data' => new InterviewResource($interview->load('questions')),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start interview',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get interview details with questions
     */
    public function show(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        return response()->json([
            'success' => true,
            'data' => new InterviewResource($interview->load(['questions', 'answers.evaluation'])),
        ]);
    }

    /**
     * Complete interview (called after last answer)
     */
    // في InterviewController@complete

public function complete(Interview $interview): JsonResponse
{
    Gate::authorize('update', $interview);

    if ($interview->status !== Interview::STATUS_IN_PROGRESS) {
        return response()->json([
            'success' => false,
            'message' => 'Interview cannot be completed in its current state',
        ], 400);
    }

    // Check if all questions are answered
    $answeredCount = $interview->answers()->count();
    $totalQuestions = $interview->questions()->count();

    if ($answeredCount < $totalQuestions) {
        return response()->json([
            'success' => false,
            'message' => "Only {$answeredCount} of {$totalQuestions} questions answered",
        ], 400);
    }

    // ✅ أولاً: غير الحالة
    $interview->update([
        'status' => Interview::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    // ✅ ثانياً: افحص إذا كل الإجابات اتجهزت
    $allAnswersProcessed = $interview->hasAllAnswersProcessed();

    if ($allAnswersProcessed) {
        // كل شيء جاهز، شغل التقرير فوراً
        dispatch(new \App\Jobs\GenerateFinalReportJob($interview))
            ->onQueue('reports');

        Log::info('Final report generation dispatched from complete()', [
            'interview_id' => $interview->id,
            'all_answers_processed' => true
        ]);
    } else {
        // الإجابات لسه بتتجهز، خليها تتابع بنفسها
        Log::info('Answers still processing, will generate report when done', [
            'interview_id' => $interview->id,
            'processed' => $interview->answers()->where('status', 'evaluated')->count(),
            'total' => $totalQuestions
        ]);
    }

    return response()->json([
        'success' => true,
        'message' => 'Interview completed. Final report is being generated.',
        'data' => [
            'interview_id' => $interview->id,
            'status' => $allAnswersProcessed ? 'generating_report' : 'processing_answers',
            'estimated_time_seconds' => 60,
        ],
    ]);
}

    /**
     * Check final report generation status
     */
    public function checkFinalStatus(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        $hasReport = $interview->finalReport()->exists();
        $allAnswersProcessed = $interview->hasAllAnswersProcessed();

        return response()->json([
            'success' => true,
            'data' => [
                'interview_id' => $interview->id,
                'status' => $interview->status,
                'report_ready' => $hasReport,
                'all_answers_processed' => $allAnswersProcessed,
                'answers_processed' => $interview->answers()->where('status', 'evaluated')->count(),
                'total_answers' => $interview->answers()->count(),
            ],
        ]);
    }

    /**
     * Get final report
     */
    public function getFinalReport(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        $report = $interview->finalReport;

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Final report not yet available',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new FinalReportResource($report),
        ]);
    }

    /**
     * List user's interviews
     */
    public function index(Request $request): JsonResponse
    {
        $interviews = $request->user()
            ->interviews()
            ->with(['questions', 'finalReport'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => InterviewResource::collection($interviews),
            'meta' => [
                'current_page' => $interviews->currentPage(),
                'total' => $interviews->total(),
                'per_page' => $interviews->perPage(),
            ],
        ]);
    }

    /**
 * Check if report is ready (polling endpoint)
 */
public function checkReportReady(Interview $interview): JsonResponse
{
    Gate::authorize('view', $interview);

    $report = $interview->finalReport;
     $allProcessed = $interview->hasAllAnswersProcessed();
    $evaluationsCount = $interview->evaluations()->count();
    $totalQuestions = $interview->questions()->count();

    return response()->json([
        'success' => true,
        'ready' => $report !== null,
        'data' => $report ? new FinalReportResource($report) : null,
        'status' => $interview->status,
        'debug' => [  // ✅ هذه المعلومات تساعدك تعرف وين المشكلة
            'report_exists' => $report !== null,
            'all_answers_processed' => $allProcessed,
            'evaluations_count' => $evaluationsCount,
            'total_questions' => $totalQuestions,
            'interview_status' => $interview->status,
            'answers_status' => $interview->answers()
                ->select('status')
                ->get()
                ->pluck('status')
                ->toArray()
        ]
    ]);
}

}
