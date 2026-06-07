<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;  // ❌ علق هذا السطر أو احذفه
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Interview;
use App\Models\Answer;
use App\Models\Resume;

// أضف 'bio', 'avatar', 'last_login_at' إلى مصفوفة Fillable

#[Fillable(['name', 'email', 'password', 'bio', 'avatar', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]

class User extends Authenticatable
{

    use HasFactory, Notifiable, HasApiTokens;

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function interviews()
    {
        return $this->hasMany(Interview::class);
    }

    public function activeInterviews()
    {
        return $this->interviews()->whereIn('status', ['in_progress', 'pending']);
    }

    public function completedInterviews()
    {
        return $this->interviews()->where('status', 'completed_with_report');
    }



public function answers()
{
    return $this->hasManyThrough(
        Answer::class,
        Interview::class,
        'user_id',      // interviews.user_id
        'interview_id', // answers.interview_id
        'id',           // users.id
        'id'            // interviews.id
    );
}

public function resumes()
{
    return $this->hasMany(Resume::class);
}

}
