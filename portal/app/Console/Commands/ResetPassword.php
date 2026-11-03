<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('user:reset-password {email : Email of the account}')]
#[Description('Give an account a new temporary password that must be changed at the next sign-in (run on the server)')]
class ResetPassword extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account has that email.');

            return self::FAILURE;
        }

        $password = Str::password(14, symbols: false);
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
        AuditLog::create(['action' => 'password.reset_by_cli', 'details' => ['user_id' => $user->id]]);

        $this->info("Temporary password for {$user->name}: {$password}");
        $this->line('It must be changed at the next sign-in. Hand it over privately.');

        return self::SUCCESS;
    }
}
