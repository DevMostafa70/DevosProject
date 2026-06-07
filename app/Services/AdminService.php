<?php

namespace App\Services;

use App\Models\AdminLog;
use App\Models\BroadcastNotification;
use App\Models\JobCategory;
use App\Models\Skill;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanyJob;
use App\Models\Interview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;  // ← أضف هذا السطر
use Illuminate\Support\Str;


class AdminService
{
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(): array
    {
        return [
            'total_users' => User::count(),
            'total_companies' => Company::count(),
            'total_jobs' => CompanyJob::count(),
            'total_interviews' => Interview::count(),
            'completed_interviews' => Interview::where('status', 'completed_with_report')->count(),
            'pending_companies' => Company::where('status', 'pending')->count(),
            'active_jobs' => CompanyJob::where('status', 'active')->count(),
            'recent_users' => User::orderBy('created_at', 'desc')->take(5)->get(),
            'recent_companies' => Company::orderBy('created_at', 'desc')->take(5)->get(),
        ];
    }

    /**
     * Get pending company registration requests
     * طلبات تسجيل الشركات التي تنتظر الموافقة
     */
    public function getPendingRequests()
    {
        return Company::where('status', 'pending')
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get recent admin logs
     */
    public function getRecentLogs(int $limit = 50)
    {
        return AdminLog::with('admin')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Suspend a user account
     */
    public function suspendUser(User $user, ?string $reason = null): bool
    {
        $user->update([
            'is_active' => false,
        ]);

        AdminLog::log('suspend_user', 'user', $user->id, [
            'reason' => $reason,
            'user_email' => $user->email,
        ]);

        return true;
    }

    /**
     * Activate a user account
     */
    public function activateUser(User $user): bool
    {
        $user->update([
            'is_active' => true,
        ]);

        AdminLog::log('activate_user', 'user', $user->id, [
            'user_email' => $user->email,
        ]);

        return true;
    }

    /**
     * Delete a user account and all related data
     */
    public function deleteUser(User $user): bool
    {
        $email = $user->email;

        AdminLog::log('delete_user', 'user', $user->id, [
            'user_email' => $email,
        ]);

        return $user->delete();
    }

    /**
     * Approve a company registration
     */
    public function approveCompany(Company $company, ?string $notes = null): bool
    {
        $company->update([
            'status' => 'approved',
            'is_verified' => true,
            'admin_notes' => $notes,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        // Activate the associated user
        $company->user->update(['is_active' => true]);

        AdminLog::log('approve_company', 'company', $company->id, [
            'company_name' => $company->company_name,
            'notes' => $notes,
        ]);

        return true;
    }

    /**
     * Reject a company registration
     */
    public function rejectCompany(Company $company, string $reason): bool
    {
        $company->update([
            'status' => 'rejected',
            'admin_notes' => $reason,
        ]);

        AdminLog::log('reject_company', 'company', $company->id, [
            'company_name' => $company->company_name,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Send broadcast notification to users
     */
   /**
 * Send broadcast notification to users
 */
public function sendBroadcastNotification(string $title, string $message, string $targetType, bool $sendEmail = false): array
{
    try {
        $query = User::query();

        switch ($targetType) {
            case 'companies':
                $query->where('role', 'company')->where('is_active', true);
                break;
            case 'candidates':
                $query->where('role', 'candidate')->where('is_active', true);
                break;
            default:
                $query->whereIn('role', ['candidate', 'company'])->where('is_active', true);
                break;
        }

        $users = $query->get();
        $sentCount = 0;

        foreach ($users as $user) {
            // استخدام DatabaseNotification بدلاً من create مباشرة

            DatabaseNotification::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'broadcast',
                'notifiable_type' => get_class($user),
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => $title,
                    'message' => $message,
                    'sender' => 'admin',
                    'sender_name' => auth()->user()->name ?? 'Admin',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sentCount++;
        }

        // Log the broadcast
        $broadcast = BroadcastNotification::create([
            'admin_id' => auth()->id(),
            'title' => $title,
            'message' => $message,
            'target_type' => $targetType,
            'sent_via_email' => $sendEmail,
            'sent_at' => now(),
            'sent_count' => $sentCount,
        ]);

        AdminLog::log('send_broadcast', 'broadcast', $broadcast->id, [
            'title' => $title,
            'target_type' => $targetType,
            'sent_count' => $sentCount,
        ]);

        return [
            'sent_count' => $sentCount,
            'broadcast_id' => $broadcast->id,
        ];

    } catch (\Exception $e) {
        Log::error('sendBroadcastNotification failed: ' . $e->getMessage());

        return [
            'sent_count' => 0,
            'broadcast_id' => null,
            'error' => $e->getMessage(),
        ];
    }
}

    /**
     * Get all skills with pagination
     */
    public function getSkills($perPage = 20)
    {
        return Skill::orderBy('name')->paginate($perPage);
    }

    /**
     * Create a new skill
     */
    public function createSkill(array $data): Skill
    {
        $skill = Skill::create($data);

        AdminLog::log('create_skill', 'skill', $skill->id, [
            'skill_name' => $skill->name,
        ]);

        return $skill;
    }

    /**
     * Update a skill
     */
    public function updateSkill(Skill $skill, array $data): Skill
    {
        $oldName = $skill->name;

        $skill->update($data);

        AdminLog::log('update_skill', 'skill', $skill->id, [
            'old_name' => $oldName,
            'new_name' => $skill->name,
        ]);

        return $skill;
    }

    /**
     * Delete a skill
     */
    public function deleteSkill(Skill $skill): bool
    {
        AdminLog::log('delete_skill', 'skill', $skill->id, [
            'skill_name' => $skill->name,
        ]);

        return $skill->delete();
    }

    /**
     * Get all job categories
     */
    public function getJobCategories($perPage = 20)
    {
        return JobCategory::orderBy('sort_order')->paginate($perPage);
    }

    /**
     * Create a new job category
     */
    public function createJobCategory(array $data): JobCategory
    {
        $category = JobCategory::create($data);

        AdminLog::log('create_category', 'category', $category->id, [
            'category_name' => $category->name_ar,
        ]);

        return $category;
    }

    /**
     * Update a job category
     */
    public function updateJobCategory(JobCategory $category, array $data): JobCategory
    {
        $oldName = $category->name_ar;

        $category->update($data);

        AdminLog::log('update_category', 'category', $category->id, [
            'old_name' => $oldName,
            'new_name' => $category->name_ar,
        ]);

        return $category;
    }

    /**
     * Delete a job category
     */
    public function deleteJobCategory(JobCategory $category): bool
    {
        AdminLog::log('delete_category', 'category', $category->id, [
            'category_name' => $category->name_ar,
        ]);

        return $category->delete();
    }
}
