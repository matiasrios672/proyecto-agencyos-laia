<?php

namespace App\Mail;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnouncementMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Announcement $announcement, public string $clientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->announcement->subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.announcement', with: [
            'name' => $this->clientName,
            'body' => $this->announcement->body,
        ]);
    }
}
