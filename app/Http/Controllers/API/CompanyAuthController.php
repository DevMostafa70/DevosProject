<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class CompanyAuthController extends Controller
{
    /**
     * Register a new company (requires admin approval)
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'company_name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        return DB::transaction(function () use ($request) {
            // Create user account (inactive until admin approval)
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'company',
                'is_active' => false,  // ← غير مفعل حتى موافقة الأدمن
            ]);

            // Create company profile with pending status
            $company = Company::create([
                'user_id' => $user->id,
                'company_name' => $request->company_name,
                'industry' => $request->industry,
                'phone' => $request->phone,
                'status' => 'pending',  // ← ينتظر الموافقة
                'is_verified' => false,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company registered successfully. Your account is pending admin approval.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'company' => [
                        'id' => $company->id,
                        'company_name' => $company->company_name,
                        'status' => $company->status,
                    ],
                ],
            ], 201);
        });
    }
}
