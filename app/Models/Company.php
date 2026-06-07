<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'logo',
        'industry',
        'website',
        'description',
        'phone',
        'address',
        'is_verified',
        'status',
        'admin_notes',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * علاقة لجلب وظائف الشركة (للتفرقة بين CompanyJob و Laravel Job)
     */
    public function companyJobs(): HasMany
    {
        return $this->hasMany(CompanyJob::class);
    }

    /**
     * علاقة لجلب وظائف الشركة (اسم مختصر - يفضل استخدامه)
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(CompanyJob::class, 'company_id');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    // علاقة مع المستخدم الذي قام بالموافقة أو الرفض
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // أضف هذه الدوال
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function approve(?string $notes = null, ?int $adminId = null): void
    {
        $this->update([
            'status' => 'approved',
            'admin_notes' => $notes,
            'approved_at' => now(),
            'approved_by' => $adminId ?? auth()->id(),
        ]);

        // تفعيل حساب المستخدم المرتبط
        if ($this->user) {
            $this->user->update(['is_active' => true]);
        }
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'admin_notes' => $reason,
        ]);
    }
}
