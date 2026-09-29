<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin';

    protected $description = 'Create a platform administrator without a seeded default credential';

    public function handle(): int
    {
        $name = $this->ask('Administrator name');
        $email = strtolower((string) $this->ask('Administrator email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::query()->where('email', $email)->exists()) {
            $this->error('The email is invalid or already in use.');

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        if (! $password || $password !== $this->secret('Confirm password') || strlen($password) < 12) {
            $this->error('Passwords must match and contain at least 12 characters.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'account_type' => 'super_admin',
            'status' => 'active',
        ]);

        $this->info('Platform administrator created.');

        return self::SUCCESS;
    }
}
