<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'resume' => 'required|file|mimes:pdf,docx,txt|max:5120', // 5MB max
            'target_position' => 'nullable|string|max:255',
            'target_skills' => 'nullable|array',
            'target_skills.*' => 'string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'resume.required' => 'Please upload a resume file',
            'resume.file' => 'Invalid file format',
            'resume.mimes' => 'Only PDF, DOCX, or TXT files are allowed',
            'resume.max' => 'File size must not exceed 5MB',
        ];
    }
}
