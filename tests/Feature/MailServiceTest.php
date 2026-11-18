<?php

namespace Tests\Feature;

use App\Mail\GeneralMessage;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_general_message_to_each_recipient(): void
    {
        Mail::fake();
        $user = User::factory()->create(['name' => 'Amina Otieno', 'email' => 'amina@example.test']);

        $sent = app(MailService::class)->send([$user, 'ops@example.test'], 'Line 2 maintenance', "Line 2 stops at noon.\n\nPlan runs around it.", [
            'actionText' => 'Open the calendar',
            'actionUrl' => 'https://aquaops.test/maintenance',
            'replyTo' => 'maintenance@example.test',
        ]);

        $this->assertTrue($sent);
        Mail::assertSent(GeneralMessage::class, 2);
        Mail::assertSent(GeneralMessage::class, fn (GeneralMessage $message): bool => $message->hasTo('amina@example.test')
            && $message->recipientName === 'Amina Otieno'
            && $message->hasSubject('Line 2 maintenance')
            && $message->hasReplyTo('maintenance@example.test'));
        Mail::assertSent(GeneralMessage::class, fn (GeneralMessage $message): bool => $message->hasTo('ops@example.test')
            && $message->recipientName === null);
    }

    public function test_the_message_renders_paragraphs_escaped_with_the_action_link(): void
    {
        $message = new GeneralMessage(
            messageSubject: 'Hello',
            messageBody: "First <b>paragraph</b>.\n\nSecond paragraph.",
            recipientName: 'Amina',
            actionText: 'Open AquaOps',
            actionUrl: 'https://aquaops.test',
        );

        $message->assertSeeInHtml('Hello Amina,', false);
        $message->assertSeeInHtml('<p>First &lt;b&gt;paragraph&lt;/b&gt;.</p>', false);
        $message->assertSeeInHtml('<p>Second paragraph.</p>', false);
        $message->assertSeeInHtml('<a href="https://aquaops.test">Open AquaOps</a>', false);
        $message->assertSeeInText('Open AquaOps: https://aquaops.test');
    }

    public function test_a_transport_failure_returns_false_instead_of_throwing(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new TransportException('Connection refused'));

        $this->assertFalse(app(MailService::class)->send('ops@example.test', 'Subject', 'Body'));
    }

    public function test_the_test_email_command_sends_to_the_given_address(): void
    {
        Mail::fake();

        $this->artisan('app:send-test-email', ['email' => 'ops@example.test'])->assertSuccessful();

        Mail::assertSent(GeneralMessage::class, fn (GeneralMessage $message): bool => $message->hasTo('ops@example.test'));
    }

    public function test_the_test_email_command_rejects_an_invalid_address(): void
    {
        Mail::fake();

        $this->artisan('app:send-test-email', ['email' => 'not-an-email'])
            ->expectsOutput('Enter a valid email address.')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_the_test_email_command_fails_when_the_transport_fails(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new TransportException('Connection refused'));

        $this->artisan('app:send-test-email', ['email' => 'ops@example.test'])->assertFailed();
    }
}
