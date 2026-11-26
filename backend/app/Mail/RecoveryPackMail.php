<?php

namespace App\Mail;

use App\Models\RecoveryPack;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecoveryPackMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RecoveryPack $pack) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Dwell Coffee — Daily Off-Site Disaster Recovery Pack (' . now()->format('Y-m-d') . ')'
        );
    }

    public function content(): Content
    {
        $note = $this->pack->photos_omitted
            ? '<p style="color:#b45309;"><strong>Note:</strong> Equipment photos were excluded from this attachment to keep the file size at or below 10 MB.</p>'
            : '';

        return new Content(
            htmlString: '<h2>Dwell Coffee — Off-Site Disaster Recovery Pack</h2>'
                . '<p>Attached is your AES-256 password-protected Recovery Pack generated on <strong>' . now()->format('Y-m-d H:i') . '</strong>.</p>'
                . '<p>Unlock the ZIP file using the Recovery Pack password configured in your POS settings.</p>'
                . $note
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath(storage_path('app/' . $this->pack->file_ref))
                ->as(basename($this->pack->file_ref))
                ->withMime('application/zip'),
        ];
    }
}