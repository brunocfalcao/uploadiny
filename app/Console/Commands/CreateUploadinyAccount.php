<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateUploadinyAccount extends Command
{
    protected $signature = 'uploadiny:account {email=bruno@uploadiny.com} {--generate : Save initial credentials to a private local file}';

    protected $description = 'Create the personal Uploadiny account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('This account already exists. Its password was not changed.');

            return self::FAILURE;
        }
        if (User::query()->exists()) {
            $this->error('Uploadiny already has its personal account.');

            return self::FAILURE;
        }
        $password = $this->option('generate') ? Str::password(24) : $this->secret('Password (at least 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Use at least 12 characters.');

            return self::FAILURE;
        }
        if ($this->option('generate')) {
            $path = Storage::disk('local')->path('initial-login.txt');
            if (is_file($path)) {
                $this->error('An initial credentials file already exists.');

                return self::FAILURE;
            }
            if (! Storage::disk('local')->put('initial-login.txt', "Email: {$email}\nPassword: {$password}\n")) {
                $this->error('Credentials could not be saved.');

                return self::FAILURE;
            }
            chmod($path, 0600);
        }
        User::create(['name' => 'Bruno', 'email' => $email, 'password' => $password]);
        $this->info('Personal account created. Initial credentials are stored privately when --generate is used.');

        return self::SUCCESS;
    }
}
