<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadResumeRequest;
use App\Models\Resume;
use App\Services\ResumeAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResumeController extends Controller
{
    protected ResumeAnalysisService $resumeService;

    public function __construct(ResumeAnalysisService $resumeService)
    {
        $this->resumeService = $resumeService;
    }

    /**
     * Upload and analyze resume
     */
    public function upload(UploadResumeRequest $request): JsonResponse
    {
        $user = Auth::user();

        // Upload file
        $resume = $this->resumeService->upload(
            $user,
            $request->file('resume'),
            $request->input('target_position'),
            $request->input('target_skills')
        );

        // Extract text and analyze
        try {
            $analysis = $this->resumeService->analyze($resume);

            return response()->json([
                'success' => true,
                'message' => 'Resume uploaded and analyzed successfully',
                'data' => [
                    'id' => $resume->id,
                    'file_name' => $resume->file_name,
                    'ats_score' => $analysis['ats_score'] ?? null,
                    'analysis' => $analysis,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to analyze resume: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get analysis for a specific resume
     */
    public function show(Resume $resume): JsonResponse
    {
        if ($resume->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $resume->id,
                'file_name' => $resume->file_name,
                'target_position' => $resume->target_position,
                'target_skills' => $resume->target_skills,
                'analysis' => $resume->analysis_result,
                'ats_score' => $resume->ats_score,
                'analyzed_at' => $resume->analyzed_at,
                'created_at' => $resume->created_at,
            ],
        ]);
    }

    /**
     * Get latest resume analysis for user
     */
    public function latest(): JsonResponse
    {
        $user = Auth::user();
        $resume = $this->resumeService->getLatestResume($user);

        if (!$resume) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No resume found',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $resume->id,
                'file_name' => $resume->file_name,
                'target_position' => $resume->target_position,
                'target_skills' => $resume->target_skills,
                'analysis' => $resume->analysis_result,
                'ats_score' => $resume->ats_score,
                'analyzed_at' => $resume->analyzed_at,
            ],
        ]);
    }

    /**
     * Get improvement suggestions for resume
     */
    public function improvements(Resume $resume): JsonResponse
    {
        if ($resume->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        try {
            $improvements = $this->resumeService->getImprovements($resume);

            return response()->json([
                'success' => true,
                'data' => $improvements,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate improvements: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete resume
     */
    public function destroy(Resume $resume): JsonResponse
    {
        if ($resume->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $this->resumeService->delete($resume);

        return response()->json([
            'success' => true,
            'message' => 'Resume deleted successfully',
        ]);
    }

    /**
     * Get all user resumes
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $resumes = $user->resumes()
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $resumes->map(function ($resume) {
                return [
                    'id' => $resume->id,
                    'file_name' => $resume->file_name,
                    'ats_score' => $resume->ats_score,
                    'created_at' => $resume->created_at,
                    'analyzed_at' => $resume->analyzed_at,
                ];
            }),
            'meta' => [
                'current_page' => $resumes->currentPage(),
                'total' => $resumes->total(),
                'per_page' => $resumes->perPage(),
            ],
        ]);
    }
}
