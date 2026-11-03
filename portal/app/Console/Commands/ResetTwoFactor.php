<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:reset-2fa {email : Email of the account}')]
#[Description('Turn off two-factor login for an account (last resort for a locked-out HoD; run on the server)')]
class ResetTwoFactor extends Command
{
    public function handle(TwoFactorService $twoFactor): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account has that email.');

            return self::FAILURE;
        }

        $twoFactor->disable($user);
        AuditLog::create(['action' => 'two_factor.reset_by_cli', 'details' => ['user_id' => $user->id]]);
        $this->info("Two-factor login reset for {$user->name}. They will be asked to set it up again at their next sign-in.");

        return self::SUCCESS;
    }
}
