<p>Hello {{ $name }},</p>

<p>Your AquaOps account has been created. Sign in with the email address {{ $email }} and this temporary password:</p>

<p><strong>{{ $temporaryPassword }}</strong></p>

<p><a href="{{ $loginUrl }}">Sign in to AquaOps</a></p>

<p>You will be required to change this password when you first sign in.</p>

<p>This temporary password expires {{ \App\Livewire\InviteUserModal::TEMPORARY_PASSWORD_LIFETIME_HOURS }} hours after this email was sent. If it has expired, ask your AquaOps administrator for a new one.</p>
