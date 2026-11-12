<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Signature('app:create-admin {--name= : Full name of the administrator} {--email= : Email address the administrator signs in with}')]
#[Description('Create an active administrator account (the password is prompted for, never passed as an argument)')]
class CreateAdministrator extends Command
{
    public function handle(SettingsService $settings): int
    {
        $administratorRole = Role::query()->where('slug', 'administrator')->first();

        if ($administratorRole === null) {
            $this->error('The administrator role does not exist yet. Run `php artisan db:seed --force` first, then run this command again.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?? $this->ask('Full name')));
        $email = trim((string) ($this->option('email') ?? $this->ask('Email address')));

        $details = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
        ], [
            'email.unique' => 'A user with this email address already exists.',
        ]);

        if ($details->fails()) {
            return $this->failWith($details->errors()->all());
        }

        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $credentials = Validator::make([
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'password' => ['required', 'string', 'min:'.$settings->get('security.password_min_length', 8), 'max:255', 'confirmed'],
        ]);

        if ($credentials->fails()) {
            return $this->failWith($credentials->errors()->all());
        }

        $administrator = DB::transaction(function () use ($administratorRole, $name, $email, $password): User {
            $administrator = User::create([
                'name' => $name,
                'email' => $email,
                'password_hash' => Hash::make($password),
                'role_id' => $administratorRole->id,
                'status' => 'active',
                'must_change_password' => false,
            ]);

            AuditLog::create([
                'user_id' => null,
                'target_user_id' => $administrator->id,
                'action' => 'user.created',
                'table_name' => 'users',
                'old_values' => [],
                'new_values' => [
                    'name' => $administrator->name,
                    'email' => $administrator->email,
                    'role' => 'administrator',
                    'status' => 'active',
                    'must_change_password' => false,
                    'source' => 'console:app:create-admin',
                ],
            ]);

            return $administrator;
        });

        $this->info("Administrator {$administrator->email} created. They can sign in now.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $messages
     */
    private function failWith(array $messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
