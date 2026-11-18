<?php

namespace App\Mail;

use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A plain branded email: a greeting, a body of paragraphs and an optional call-to-action button.
 */
class GeneralMessage extends Mailable
{
    use Queueable;

    public function __construct(
        public string $messageSubject,
        public string $messageBody,
        public ?string $recipientName = null,
        public ?string $actionText = null,
        public ?string $actionUrl = null,
        public ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->messageSubject,
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.general-message',
            text: 'emails.general-message-text',
            with: [
                'organisationName' => app(Branding::class)->organisationName(),
                'paragraphs' => $this->paragraphs(),
            ],
        );
    }

    /**
     * @return array<int, string>
     */
    private function paragraphs(): array
    {
        $paragraphs = preg_split('/\R{2,}/', trim($this->messageBody)) ?: [];

        return array_values(array_filter(array_map('trim', $paragraphs), fn (string $paragraph): bool => $paragraph !== ''));
    }
}
