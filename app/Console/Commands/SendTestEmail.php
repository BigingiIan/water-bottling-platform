<?php

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('app:send-test-email {email : Address to send the test email to}')]
#[Description('Send a test email through the configured mailer to confirm delivery works')]
class SendTestEmail extends Command
{
    public function handle(MailService $mail): int
    {
        $email = trim((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER is \"{$mailer}\": the message is recorded, not delivered. Set a real mailer (smtp, ses, postmark, resend) to send it.");
        }

        $sent = $mail->send(
            $email,
            config('app.name').' test email',
            "This is a test email from ".config('app.name').".\n\nIf you can read it, outgoing email is working.",
        );

        if (! $sent) {
            $this->error("The {$mailer} mailer could not send the email. Check the MAIL_* settings and the log for details.");

            return self::FAILURE;
        }

        $this->info("Test email sent to {$email} with the {$mailer} mailer.");

        return self::SUCCESS;
    }
}
