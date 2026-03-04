<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $report
     */
    public function __construct(
        public array $report,
        public User $teacher,
        public string $pdfBinary,
        public string $pdfFilename,
    ) {
    }

    public function envelope(): Envelope
    {
        $examTitle = trim((string) ($this->report['exam']['title'] ?? 'Exam'));
        $roomCode = trim((string) ($this->report['room']['code'] ?? 'Room'));
        $subject = $examTitle !== ''
            ? 'Your Exam Report: ' . $examTitle . ($roomCode !== '' ? ' (' . $roomCode . ')' : '')
            : 'Your Exam Report';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student_report',
            with: [
                'report' => $this->report,
                'teacher' => [
                    'id' => (int) $this->teacher->id,
                    'name' => $this->teacher->name,
                    'email' => $this->teacher->email,
                ],
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => $this->pdfBinary,
                $this->pdfFilename
            )->withMime('application/pdf'),
        ];
    }
}
