<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCompanyController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
        // $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Get pending company registration requests
     */
    public function pendingRequests(): JsonResponse
    {
        $requests = $this->adminService->getPendingRequests();

        return response()->json([
            'success' => true,
            'data' => $requests->map(function ($company) {
                return [
                    'id' => $company->id,
                    'company_name' => $company->company_name,
                    'industry' => $company->industry,
                    'phone' => $company->phone,
                    'website' => $company->website,
                    'status' => $company->status,
                    'user' => [
                        'id' => $company->user->id,
                        'name' => $company->user->name,
                        'email' => $company->user->email,
                    ],
                    'created_at' => $company->created_at,
                ];
            }),
        ]);
    }

    /**
     * Approve a company registration
     */
    public function approve(Company $company, Request $request): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $this->adminService->approveCompany($company, $request->notes);

        return response()->json([
            'success' => true,
            'message' => 'Company approved successfully',
        ]);
    }

    /**
     * Reject a company registration
     */
    public function reject(Company $company, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $this->adminService->rejectCompany($company, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'Company rejected successfully',
        ]);
    }
}
