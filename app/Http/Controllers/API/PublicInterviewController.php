<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CompanyJob;
use App\Models\Interview;
use App\Models\Job;
use App\Models\User;
use App\Services\CompanyJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PublicInterviewController extends Controller
{
    protected CompanyJobService $jobService;

    public function __construct(CompanyJobService $jobService)
    {
        $this->jobService = $jobService;
    }

    /**
     * Get job details by token (public page)
     */
    public function showJob(string $token): JsonResponse
    {
        $job = CompanyJob::where('unique_token', $token)
            ->where('status', 'active')
            ->first();

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found or expired',
            ], 404);
        }

        // Check if job is expired
        if ($job->expires_at && $job->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This job posting has expired',
            ], 410);
        }

        // Check if max candidates reached
        if ($job->hasReachedMaxCandidates()) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum number of candidates has been reached for this position',
            ], 410);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'job_id' => $job->id,
                'token' => $job->unique_token,
                'title' => $job->title,
                'description' => $job->description,
                'required_skills' => $job->required_skills,
                'company_name' => $job->company->company_name,
                'expires_at' => $job->expires_at,
            ],
        ]);
    }

    /**
     * Start interview (candidate provides name and email)
     */
    public function start(Request $request, string $token): JsonResponse
    {
        $job = CompanyJob::where('unique_token', $token)
            ->where('status', 'active')
            ->first();

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found or expired',
            ], 404);
        }

        // Validate job is still active
        if (!$job->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'This job is no longer accepting candidates',
            ], 410);
        }

        // Validate request
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'source' => 'nullable|string|max:100',
        ]);

        // Get or create candidate
        $candidate = $this->jobService->getOrCreateCandidate(
            $request->email,
            $request->name,
            $request->source
        );

        // Check if candidate already completed interview for this job
        $existingApplication = $job->candidates()
            ->where('candidate_id', $candidate->id)
            ->first();

        if ($existingApplication && $existingApplication->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'You have already completed the interview for this position',
            ], 400);
        }

        // Initialize interview
        try {
            $result = $this->jobService->initializeInterview($job, $candidate, $request->source);

            // Login the candidate
            Auth::login($candidate);
            $token = $candidate->createToken('interview-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Interview ready to start',
                'data' => [
                    'interview_id' => $result['interview']->id,
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get interview details for candidate
     */
    public function getInterview(string $token, int $interviewId): JsonResponse
    {
        $job = CompanyJob::where('unique_token', $token)->firstOrFail();

        $jobCandidate = $job->candidates()
            ->where('candidate_id', Auth::id())
            ->where('interview_id', $interviewId)
            ->first();

        if (!$jobCandidate) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
            ], 404);
        }

        $interview = $jobCandidate->interview;

        if (!$interview) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not initialized',
            ], 404);
        }

        $questions = $interview->questions()
            ->orderBy('order')
            ->get()
            ->map(function ($question) use ($interview) {
                $answer = $interview->answers()->where('question_id', $question->id)->first();

                return [
                    'id' => $question->id,
                    'text' => $question->question_text,
                    'type' => $question->type,
                    'order' => $question->order,
                    'answered' => !is_null($answer),
                    'answer_transcript' => $answer?->transcription,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'interview_id' => $interview->id,
                'status' => $interview->status,
                'position' => $interview->position,
                'total_questions' => $interview->questions()->count(),
                'answered_questions' => $interview->answers()->count(),
                'questions' => $questions,
                'hide_score' => $job->hide_score_from_candidate,
            ],
        ]);
    }

    /**
     * Complete interview (called after last answer)
     */
    public function complete(string $token, int $interviewId): JsonResponse
    {
        $job = CompanyJob::where('unique_token', $token)->firstOrFail();

        $jobCandidate = $job->candidates()
            ->where('candidate_id', Auth::id())
            ->where('interview_id', $interviewId)
            ->first();

        if (!$jobCandidate) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
            ], 404);
        }

        $interview = $jobCandidate->interview;

        if (!$interview || $interview->status !== Interview::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => false,
                'message' => 'Interview cannot be completed',
            ], 400);
        }

        // Mark interview as completed
        $interview->update([
            'status' => Interview::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // Trigger final report generation (queue or async)
        // This would be handled by your existing evaluation system

        return response()->json([
            'success' => true,
            'message' => $job->hide_score_from_candidate
                ? 'Thank you! Your interview has been submitted to the company.'
                : 'Thank you! Your interview has been completed.',
        ]);
    }
}
