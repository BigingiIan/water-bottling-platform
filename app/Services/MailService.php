<?php

namespace App\Services;

use App\Mail\GeneralMessage;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Sends email through the configured mailer (MAIL_MAILER) and reports delivery failures
 * as a false return instead of an exception, so callers can tell the user what happened.
 */
class MailService
{
    /**
     * Send a plain branded message.
     *
     * @param  User|string|array<int, User|string>  $recipients
     * @param  array{actionText?: string, actionUrl?: string, replyTo?: string}  $options
     */
    public function send(User|string|array $recipients, string $subject, string $body, array $options = []): bool
    {
        $recipients = is_array($recipients) ? $recipients : [$recipients];
        $sentToAll = true;

        foreach ($recipients as $recipient) {
            $message = new GeneralMessage(
                messageSubject: $subject,
                messageBody: $body,
                recipientName: $recipient instanceof User ? $recipient->name : null,
                actionText: $options['actionText'] ?? null,
                actionUrl: $options['actionUrl'] ?? null,
                replyToAddress: $options['replyTo'] ?? null,
            );

            $sentToAll = $this->sendMailable($recipient, $message) && $sentToAll;
        }

        return $sentToAll;
    }

    /**
     * Send any mailable now, logging and returning false when the transport rejects it.
     */
    public function sendMailable(User|string $recipient, Mailable $mailable): bool
    {
        try {
            Mail::to($recipient)->send($mailable);

            return true;
        } catch (TransportExceptionInterface $exception) {
            Log::error('Failed to send email.', [
                'mailable' => $mailable::class,
                'recipient' => $recipient instanceof User ? $recipient->email : $recipient,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    /**
     * Queue any mailable for a queue worker to send.
     */
    public function queueMailable(User|string $recipient, Mailable $mailable): void
    {
        Mail::to($recipient)->queue($mailable);
    }
}
