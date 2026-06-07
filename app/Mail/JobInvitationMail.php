<?php

namespace App\Mail;

use App\Models\CompanyJob;
use App\Models\EmailInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JobInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public EmailInvitation $invitation;
    public CompanyJob $job;

    public function __construct(EmailInvitation $invitation, CompanyJob $job)
    {
        $this->invitation = $invitation;
        $this->job = $job;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "دعوة لمقابلة وظيفية - {$this->job->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.job-invitation',
            with: [
                'candidateName' => $this->invitation->name,
                'jobTitle' => $this->job->title,
                'companyName' => $this->job->company->company_name,
                'invitationLink' => $this->getInvitationLink(),
                'skills' => $this->job->required_skills,
            ],
        );
    }

    private function getInvitationLink(): string
    {
        // الرابط يحتوي على الإيميل والاسم لتسهيل الدخول
        $token = $this->job->unique_token;
        $email = urlencode($this->invitation->email);
        $name = urlencode($this->invitation->name);

        return url("/interview/join/{$token}?email={$email}&name={$name}");
    }
}
