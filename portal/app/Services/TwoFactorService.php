<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 6 digits, 30 s) plus one-time recovery codes.
 * No external package: it works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, etc.
 */
class TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function uri(User $user, string $secret): string
    {
        $issuer = config('portal.department').', FULafia';

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email).'?secret='.$secret
            .'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** The code for a given 30-second step. */
    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code against the user's secret (one step either side for clock drift). A step that was
     * already used cannot be used again, so a code seen over a shoulder or in a log is worthless.
     */
    public function verify(User $user, string $input, ?string $secret = null): bool
    {
        $input = preg_replace('/\D/', '', $input);
        $secret ??= $user->two_factor_secret;
        if (! $secret || strlen($input) !== self::DIGITS) {
            return false;
        }

        $now = $this->currentStep();
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (hash_equals($this->code($secret, $step), $input) && $step > (int) $user->two_factor_last_step) {
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> plain codes, shown to the user once */
    public function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes))])->save();

        return $codes;
    }

    /** Use (and burn) a recovery code. */
    public function useRecoveryCode(User $user, string $input): bool
    {
        $input = Str::lower(trim($input));
        $hashes = json_decode((string) $user->two_factor_recovery_codes, true) ?: [];

        foreach ($hashes as $i => $hash) {
            if (Hash::check($input, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();

                return true;
            }
        }

        return false;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count(json_decode((string) $user->two_factor_recovery_codes, true) ?: []);
    }

    public function enable(User $user): array
    {
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->newRecoveryCodes($user);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null, 'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null, 'two_factor_last_step' => null,
        ])->save();
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret))) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
