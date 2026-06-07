<?php

namespace App\Imports;

use App\Jobs\SendInvitationEmailJob;
use App\Models\CompanyJob;
use App\Models\EmailInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactsImport implements ToCollection, WithHeadingRow, WithChunkReading, ShouldQueue
{
    protected $job;

    public function __construct($job)
    {
        $this->job = $job;
    }

    public function collection(Collection $rows)
    {
        Log::info('ContactsImport: Total rows received = ' . $rows->count());

        foreach ($rows as $row) {
            if (empty($row['email'])) {
                continue;
            }

            $exists = EmailInvitation::where('email', $row['email'])
                ->where('company_job_id', $this->job->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $invitation = EmailInvitation::create([
                'email' => $row['email'],
                'name' => $row['name'] ?? $row['email'],
                'company_job_id' => $this->job->id,
                'status' => 'pending',
            ]);

            SendInvitationEmailJob::dispatch($invitation, $this->job);
        }
    }

    public function chunkSize(): int
    {
        return 100;
    }
}
