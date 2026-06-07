<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateJobRequest;
use App\Http\Requests\Company\UpdateCandidateStatusRequest;
use App\Models\Company;
use App\Models\CompanyJob;
use App\Models\CompanyJobCandidate;
use App\Models\Job;
use App\Models\JobCandidate;
use App\Services\CompanyJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Imports\ContactsImport;
use App\Services\QuestionBankService;
use Maatwebsite\Excel\Facades\Excel;

//مسؤول عن جميع عمليات الشركة المتعلقة بالوظائف
class CompanyJobController extends Controller
{
    protected CompanyJobService $jobService;

    public function __construct(CompanyJobService $jobService)
    {
        $this->jobService = $jobService;
        // $this->middleware('auth:sanctum');
    }

    /**
     * Get company profile
     */
    private function getCompany(): Company
    {
        return Auth::user()->company;
    }

    /**
     * Create a new job posting
     * إنشاء إعلان وظيفة جديد
     */
    public function store(CreateJobRequest $request): JsonResponse
    {

        $company = $this->getCompany();

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company profile not found',
            ], 404);
        }

        $job = $this->jobService->createJob($company, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Job created successfully',
            'data' => [
                'job' => [
                    'id' => $job->id,
                    'title' => $job->title,
                    'unique_token' => $job->unique_token,
                    'shareable_link' => $job->getShareableLink(),
                    'expires_at' => $job->expires_at,
                    'max_candidates' => $job->max_candidates,
                ],
            ],
        ], 201);
    }
    /**
     * Get all jobs for the company
     * الحصول على جميع الوظائف للشركة
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->getCompany();

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company profile not found',
            ], 404);
        }

        $jobs = $company->jobs()
            ->withCount('candidates')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $jobs->map(function ($job) {
                return [
                    'id' => $job->id,
                    'title' => $job->title,
                    'status' => $job->status,
                    'shareable_link' => $job->getShareableLink(),
                    'candidates_count' => $job->candidates_count,
                    'completed_candidates' => $job->candidates()
                        ->whereNotNull('final_score')
                        ->count(),
                    'expires_at' => $job->expires_at,
                    'created_at' => $job->created_at,
                ];
            }),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'total' => $jobs->total(),
                'per_page' => $jobs->perPage(),
            ],
        ]);
    }

    /**
     * Get job details
     */
    public function show(CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $job->id,
                'title' => $job->title,
                'description' => $job->description,
                'required_skills' => $job->required_skills,
                'custom_questions' => $job->custom_questions,
                'difficulty' => $job->difficulty,
                'max_candidates' => $job->max_candidates,
                'expires_at' => $job->expires_at,
                'status' => $job->status,
                'shareable_link' => $job->getShareableLink(),
            ],
        ]);
    }

    /**
     * Get ranked candidates for a job
     *
     */
    public function candidates(CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $candidates = $this->jobService->getRankedCandidates($job);

        return response()->json([
            'success' => true,
            'data' => [
                'job_title' => $job->title,
                'total_candidates' => $candidates->count(),
                'candidates' => $candidates,
            ],
        ]);
    }

    /**
     * Get candidate details with full interview results
     *
     */
    public function candidateDetails(CompanyJob $job, CompanyJobCandidate $jobCandidate): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($jobCandidate->job_id !== $job->id) {
            return response()->json([
                'success' => false,
                'message' => 'Candidate not found for this job',
            ], 404);
        }

        $interview = $jobCandidate->interview;
        $finalReport = $interview?->finalReport;

        // Get all questions with answers and evaluations
        $questions = [];
        if ($interview) {
            $questions = $interview->questions()
                ->with(['answers.evaluation'])
                ->orderBy('order')
                ->get()
                ->map(function ($question) {
                    $answer = $question->answers->first();
                    $evaluation = $answer?->evaluation;

                    return [
                        'id' => $question->id,
                        'text' => $question->question_text,
                        'type' => $question->type,
                        'source' => $question->source,
                        'answer_transcript' => $answer?->transcription,
                        'score' => $evaluation ? round($evaluation->score * 10, 2) : null,
                        'strengths' => $evaluation?->strengths,
                        'weaknesses' => $evaluation?->weaknesses,
                        'feedback' => $evaluation?->detailed_feedback,
                    ];
                });
        }

        return response()->json([
            'success' => true,
            'data' => [
                'candidate' => [
                    'id' => $jobCandidate->candidate->id,
                    'name' => $jobCandidate->candidate->name,
                    'email' => $jobCandidate->candidate->email,
                ],
                'job_candidate' => [
                    'status' => $jobCandidate->status,
                    'final_score' => $jobCandidate->final_score,
                    'source' => $jobCandidate->source,
                    'company_notes' => $jobCandidate->company_notes,
                    'completed_at' => $jobCandidate->completed_at,
                ],
                'report' => $finalReport ? [
                    'executive_summary' => $finalReport->executive_summary,
                    'strengths_analysis' => $finalReport->strengths_analysis,
                    'improvement_areas' => $finalReport->improvement_areas,
                    'hiring_recommendation' => $finalReport->hiring_recommendation,
                    'technical_score' => $finalReport->technical_score ? round($finalReport->technical_score * 10, 2) : null,
                    'communication_score' => $finalReport->communication_score ? round($finalReport->communication_score * 10, 2) : null,
                    'problem_solving_score' => $finalReport->problem_solving_score ? round($finalReport->problem_solving_score * 10, 2) : null,
                ] : null,
                'questions' => $questions,
            ],
        ]);
    }

    /**
     * Update candidate status (shortlist, reject, hire)
     * تحديث حالة المرشح (قائمة مختصرة، رفض، توظيف)
     */
    public function updateCandidateStatus(
        CompanyJob $job,
        CompanyJobCandidate $jobCandidate,
        UpdateCandidateStatusRequest $request
    ): JsonResponse {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($jobCandidate->job_id !== $job->id) {
            return response()->json([
                'success' => false,
                'message' => 'Candidate not found for this job',
            ], 404);
        }

        $jobCandidate->updateStatus(
            $request->status,
            $request->company_notes
        );

        return response()->json([
            'success' => true,
            'message' => 'Candidate status updated successfully',
            'data' => [
                'status' => $jobCandidate->status,
                'company_notes' => $jobCandidate->company_notes,
            ],
        ]);
    }

    /**
     * Close/expire a job posting
     */
    public function close(CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $job->update(['status' => 'closed']);

        return response()->json([
            'success' => true,
            'message' => 'Job closed successfully',
        ]);
    }

    /**
     * Get job statistics
     * الحصول على إحصائيات الوظيفة
     */
    public function stats(CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $candidates = $job->candidates;

        $stats = [
            'total_candidates' => $candidates->count(),
            'completed_interviews' => $candidates->whereNotNull('final_score')->count(),
            'average_score' => round($candidates->avg('final_score'), 2),
            'highest_score' => round($candidates->max('final_score'), 2),
            'status_breakdown' => [
                'pending' => $candidates->where('status', 'pending')->count(),
                'completed' => $candidates->where('status', 'completed')->count(),
                'shortlisted' => $candidates->where('status', 'shortlisted')->count(),
                'rejected' => $candidates->where('status', 'rejected')->count(),
                'hired' => $candidates->where('status', 'hired')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }


    /**
     * Bulk invite candidates via Excel file
     * دعوة مرشحين جماعياً عبر ملف Excel
     */
    public function inviteBulk(Request $request, CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,csv,xls|max:10240', // 10MB max
        ]);

        try {
            // Queue the import for background processing
            Excel::queueImport(new ContactsImport($job), $request->file('excel_file'));

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully. Invitations are being processed and will be sent shortly.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get bulk invitation stats for a job
     * الحصول على إحصائيات الدعوات الجماعية لوظيفة ما
     */
    public function invitationStats(CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $stats = [
            'total' => $job->invitations()->count(),
            'sent' => $job->invitations()->where('status', 'sent')->count(),
            'pending' => $job->invitations()->where('status', 'pending')->count(),
            'failed' => $job->invitations()->where('status', 'failed')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
 * Get all invitations for a job
 */
public function invitations(CompanyJob $job, Request $request): JsonResponse
{
    $company = $this->getCompany();

    if ($job->company_id !== $company->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], 403);
    }

    $invitations = $job->invitations()
        ->orderBy('created_at', 'desc')
        ->paginate($request->get('per_page', 20));

    return response()->json([
        'success' => true,
        'data' => $invitations,
    ]);
}

    // الشركات 2

    /**
     * Upload questions file for a job
     */
    public function uploadQuestions(Request $request, CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'questions_file' => 'required|file|mimes:xlsx,csv,xls|max:10240',
        ]);

        try {
            $questionBankService = new QuestionBankService();
            $result = $questionBankService->uploadQuestions($job, $request->file('questions_file'));

            return response()->json([
                'success' => true,
                'message' => 'Questions uploaded successfully',
                'data' => [
                    'total_questions' => $result['total_questions'],
                    'question_bank_id' => $result['question_bank_id'],
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get question statistics for a job
     */
    public function questionStats(CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $questionBankService = new QuestionBankService();
        $stats = $questionBankService->getQuestionStats($job);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get question bank for a job
     */
    public function getQuestionBank(CompanyJob $job): JsonResponse
    {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $questionBank = $job->questionBank;

        if (!$questionBank) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No question bank found for this job',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $questionBank->id,
                'total_questions' => $questionBank->total_questions,
                'questions' => $questionBank->questions,
            ],
        ]);
    }
}
