<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Admin} {--password=}';

    protected $description = 'Create an admin account, or reset the password of an existing one';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');
        if (strlen((string) $password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $this->argument('email')]);
        $user->name = $user->exists ? $user->name : $this->option('name');
        $user->type = $user->type ?: 'admin';
        $user->password = Hash::make($password);
        $user->save();

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated').' admin '.$user->email);

        return self::SUCCESS;
    }
}
