<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
        // $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Get all users with pagination
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['company'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Get user details
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $user->load(['company', 'interviews']),
        ]);
    }

    /**
     * Suspend a user
     */
    public function suspend(User $user, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string',
        ]);

        $this->adminService->suspendUser($user, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'User suspended successfully',
        ]);
    }

    /**
     * Activate a user
     */
    public function activate(User $user): JsonResponse
    {
        $this->adminService->activateUser($user);

        return response()->json([
            'success' => true,
            'message' => 'User activated successfully',
        ]);
    }

    /**
     * Delete a user
     */
    public function destroy(User $user): JsonResponse
    {
        $this->adminService->deleteUser($user);

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
}
