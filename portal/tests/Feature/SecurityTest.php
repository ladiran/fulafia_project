<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-7';

    private TwoFactorService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(TwoFactorService::class);
        RateLimiter::clear('x');
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(['role' => $role, 'password' => self::PASSWORD] + $extra);
    }

    /** An admin who already has two-factor switched on; returns [user, secret]. */
    private function adminWithTwoFactor(string $role = User::ROLE_PRINCIPAL_ADMIN): array
    {
        $user = $this->user($role);
        $secret = $this->totp->newSecret();
        $user->forceFill(['two_factor_secret' => $secret])->save();
        $codes = $this->totp->enable($user);

        return [$user->fresh(), $secret, $codes];
    }

    // ---- TOTP ----

    public function test_totp_matches_the_rfc_6238_test_vector(): void
    {
        // RFC 6238 Appendix B, SHA-1, secret "12345678901234567890", T = 59 s -> 94287082 (8 digits), so 287082 as 6 digits.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $this->totp->code($secret, intdiv(59, 30)));
        $this->assertSame('081804', $this->totp->code($secret, intdiv(1111111109, 30)));
    }

    // ---- Password ----

    public function test_a_temporary_password_must_be_changed_before_anything_else(): void
    {
        $student = $this->user(User::ROLE_STUDENT, ['must_change_password' => true]);

        $this->actingAs($student)->get('/student')->assertRedirect(route('security.password'));
        $this->actingAs($student)->get('/student/results')->assertRedirect(route('security.password'));
        $this->actingAs($student)->get('/security/password')->assertOk()->assertSee('temporary password');
        $this->actingAs($student)->post('/logout')->assertRedirect('/');
    }

    public function test_changing_the_password_clears_the_flag_and_enforces_the_rules(): void
    {
        $student = $this->user(User::ROLE_STUDENT, ['must_change_password' => true]);
        $url = '/security/password';
        $good = ['current_password' => self::PASSWORD, 'password' => 'New-Passw0rd-xyz', 'password_confirmation' => 'New-Passw0rd-xyz'];

        $this->actingAs($student)->post($url, ['current_password' => 'wrong'] + $good)->assertSessionHasErrors('current_password');
        $this->actingAs($student)->post($url, ['password' => 'short1A', 'password_confirmation' => 'short1A'] + $good)->assertSessionHasErrors('password');
        $this->actingAs($student)->post($url, ['password' => 'alllowercase123', 'password_confirmation' => 'alllowercase123'] + $good)->assertSessionHasErrors('password');
        $this->actingAs($student)->post($url, ['password' => 'NoDigitsHereAtAll', 'password_confirmation' => 'NoDigitsHereAtAll'] + $good)->assertSessionHasErrors('password');
        $this->actingAs($student)->post($url, ['password_confirmation' => 'different'] + $good)->assertSessionHasErrors('password');
        $this->actingAs($student)->post($url, ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD] + $good)->assertSessionHasErrors('password');
        $this->assertTrue($student->fresh()->must_change_password);

        $this->actingAs($student)->post($url, $good)->assertSessionHasNoErrors()->assertRedirect();
        $student->refresh();
        $this->assertFalse($student->must_change_password);
        $this->assertNotNull($student->password_changed_at);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('New-Passw0rd-xyz', $student->password));
        $this->actingAs($student)->get('/student')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'password.changed']);
    }

    public function test_students_added_by_admins_get_a_temporary_password_and_can_be_reset(): void
    {
        $eo = $this->user(User::ROLE_SECONDARY_ADMIN);
        $session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);

        $this->actingAs($eo)->post('/admin/students', [
            'name' => 'Ada Obi', 'identifier' => 'FUL/PHY/25/001', 'gender' => 'female', 'entry_mode' => 'regular',
            'entry_session_id' => $session->id, 'jamb_reg_no' => '2025123456AB',
        ])->assertSessionHasNoErrors();
        $profile = StudentProfile::firstOrFail();
        $this->assertTrue($profile->user->must_change_password);

        $profile->user->forceFill(['must_change_password' => false])->save();
        $old = $profile->user->password;
        $this->actingAs($eo)->post("/admin/students/{$profile->id}/reset-password")->assertSessionHas('status');
        $profile->user->refresh();
        $this->assertTrue($profile->user->must_change_password);
        $this->assertNotSame($old, $profile->user->password);

        // Only student managers can do it.
        $this->actingAs($this->user(User::ROLE_LECTURER))->post("/admin/students/{$profile->id}/reset-password")->assertForbidden();
        $this->actingAs($this->user(User::ROLE_ADMIN, ['permissions' => ['manage_courses']]))->post("/admin/students/{$profile->id}/reset-password")->assertForbidden();
    }

    public function test_a_deactivated_account_is_signed_out_at_once(): void
    {
        $student = $this->user(User::ROLE_STUDENT);
        $this->actingAs($student)->get('/student')->assertOk();

        $student->update(['is_active' => false]);
        $this->actingAs($student)->get('/student')->assertRedirect(route('login'));
    }

    // ---- Login protection ----

    public function test_repeated_failures_lock_the_account_even_for_the_right_password(): void
    {
        $student = $this->user(User::ROLE_STUDENT, ['identifier' => 'FUL/PHY/25/777']);

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['login' => 'FUL/PHY/25/777', 'password' => 'wrong-'.$i])->assertSessionHasErrors('login');
        }
        $this->post('/login', ['login' => 'FUL/PHY/25/777', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertSame(5, AuditLog::where('action', 'login.failed')->count());
    }

    public function test_a_good_login_records_the_time_and_address_and_resets_the_counter(): void
    {
        $student = $this->user(User::ROLE_STUDENT, ['identifier' => 'FUL/PHY/25/778']);
        $this->post('/login', ['login' => 'FUL/PHY/25/778', 'password' => 'nope']);

        $this->post('/login', ['login' => 'FUL/PHY/25/778', 'password' => self::PASSWORD])->assertRedirect('/student');

        $this->assertAuthenticatedAs($student);
        $this->assertNotNull($student->fresh()->last_login_at);
        $this->assertNotNull($student->fresh()->last_login_ip);
    }

    // ---- Two-factor ----

    public function test_admins_must_set_up_two_factor_before_using_the_portal(): void
    {
        config(['portal.require_admin_2fa' => true]);
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);

        $this->actingAs($hod)->get('/admin')->assertRedirect(route('security.two-factor'));
        $this->actingAs($hod)->get('/admin/students')->assertRedirect(route('security.two-factor'));
        $this->actingAs($hod)->get('/security/two-factor')->assertOk()->assertSee('Set up two-factor');
        $this->actingAs($hod)->get('/security/password')->assertOk();

        // Students and lecturers are not forced.
        $this->actingAs($this->user(User::ROLE_STUDENT))->get('/student')->assertOk();
    }

    public function test_setting_up_two_factor_needs_a_valid_code_and_shows_recovery_codes_once(): void
    {
        config(['portal.require_admin_2fa' => true]);
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);

        $this->actingAs($hod)->get('/security/two-factor')->assertOk();
        $secret = $hod->fresh()->two_factor_secret;
        $this->assertNotEmpty($secret);

        $this->actingAs($hod)->post('/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($hod->fresh()->hasTwoFactor());

        $code = $this->totp->code($secret, $this->totp->currentStep());
        $this->actingAs($hod)->post('/security/two-factor/confirm', ['code' => $code])->assertSessionHasNoErrors()->assertSessionHas('recovery_codes');
        $this->assertTrue($hod->fresh()->hasTwoFactor());
        $this->actingAs($hod)->get('/admin')->assertOk();

        // The secret is encrypted at rest.
        $this->assertNotSame($secret, \Illuminate\Support\Facades\DB::table('users')->where('id', $hod->id)->value('two_factor_secret'));
    }

    public function test_login_with_two_factor_needs_the_code_and_a_used_code_cannot_be_replayed(): void
    {
        [$hod, $secret] = $this->adminWithTwoFactor();
        $next = $this->totp->code($secret, $this->totp->currentStep() + 1);

        $this->post('/login', ['login' => $hod->email, 'password' => self::PASSWORD])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get('/admin')->assertRedirect(route('login'));

        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $next])->assertRedirect();
        $this->assertAuthenticatedAs($hod);

        // Same code again (new sign-in): refused.
        $this->post('/logout');
        $this->post('/login', ['login' => $hod->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $next])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$hod, , $codes] = $this->adminWithTwoFactor();

        $this->post('/login', ['login' => $hod->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect();
        $this->assertAuthenticatedAs($hod);
        $this->assertSame(7, $this->totp->recoveryCodesLeft($hod->fresh()));

        $this->post('/logout');
        $this->post('/login', ['login' => $hod->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_second_step_expires_and_is_rate_limited(): void
    {
        [$hod, $secret] = $this->adminWithTwoFactor();

        $this->post('/login', ['login' => $hod->email, 'password' => self::PASSWORD]);
        $this->withSession(['two_factor.started' => now()->subMinutes(10)->timestamp])
            ->post('/two-factor-challenge', ['code' => $this->totp->code($secret, $this->totp->currentStep() + 1)])
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', ['login' => $hod->email, 'password' => self::PASSWORD]);
        foreach (range(1, 5) as $i) {
            $this->post('/two-factor-challenge', ['code' => '11111'.$i]);
        }
        $this->post('/two-factor-challenge', ['code' => $this->totp->code($secret, $this->totp->currentStep() + 1)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_non_admins_may_turn_two_factor_off_but_admins_may_not(): void
    {
        config(['portal.require_admin_2fa' => true]);
        $lecturer = $this->user(User::ROLE_LECTURER);
        $lecturer->forceFill(['two_factor_secret' => $this->totp->newSecret()])->save();
        $this->totp->enable($lecturer);

        $this->actingAs($lecturer)->post('/security/two-factor/disable', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->actingAs($lecturer)->post('/security/two-factor/disable', ['current_password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->assertFalse($lecturer->fresh()->hasTwoFactor());

        [$hod] = $this->adminWithTwoFactor();
        $this->actingAs($hod)->post('/security/two-factor/disable', ['current_password' => self::PASSWORD])->assertForbidden();
        $this->assertTrue($hod->fresh()->hasTwoFactor());
    }

    public function test_recovery_codes_can_be_regenerated_with_the_password(): void
    {
        [$hod, , $old] = $this->adminWithTwoFactor();

        $this->actingAs($hod)->post('/security/two-factor/recovery-codes', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->actingAs($hod)->post('/security/two-factor/recovery-codes', ['current_password' => self::PASSWORD])->assertSessionHas('recovery_codes');
        $this->assertFalse($this->totp->useRecoveryCode($hod->fresh(), $old[0]), 'old codes stop working');
    }

    public function test_only_the_hod_can_reset_another_admins_two_factor(): void
    {
        [$hod] = $this->adminWithTwoFactor();
        [$eo] = $this->adminWithTwoFactor(User::ROLE_SECONDARY_ADMIN);

        $this->actingAs($eo)->post("/admin/users/{$hod->id}/reset-two-factor")->assertForbidden();
        $this->actingAs($hod)->post("/admin/users/{$hod->id}/reset-two-factor")->assertForbidden(); // the HoD uses recovery codes or the server command
        $this->assertTrue($eo->fresh()->hasTwoFactor());

        $this->actingAs($hod)->post("/admin/users/{$eo->id}/reset-two-factor")->assertSessionHasNoErrors();
        $this->assertFalse($eo->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor.reset_by_hod']);
    }

    public function test_server_commands_reset_two_factor_and_passwords(): void
    {
        [$hod] = $this->adminWithTwoFactor();

        $this->artisan('user:reset-2fa', ['email' => $hod->email])->assertSuccessful();
        $this->assertFalse($hod->fresh()->hasTwoFactor());

        $this->artisan('user:reset-password', ['email' => $hod->email])->assertSuccessful();
        $this->assertTrue($hod->fresh()->must_change_password);
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check(self::PASSWORD, $hod->fresh()->password));

        $this->artisan('user:reset-2fa', ['email' => 'nobody@example.com'])->assertFailed();
    }

    // ---- Headers and network ----

    public function test_security_headers_are_sent_everywhere(): void
    {
        $public = $this->get('/');
        $public->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $csp = $public->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertNull($public->headers->get('Strict-Transport-Security'), 'HSTS only over https');

        $this->get('/login', ['X-Forwarded-Proto' => 'https'])->assertOk();
        $this->withServerVariables(['HTTPS' => 'on'])->get('/')->assertHeader('Strict-Transport-Security');

        $this->actingAs($this->user(User::ROLE_STUDENT))->get('/student')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_no_view_uses_inline_javascript(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile() && preg_match('/\bon(submit|click|change|load|input|error)\s*=|javascript:/i', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'inline handlers would be blocked by the Content Security Policy');
        $this->assertFileExists(public_path('js/app.js'));
    }

    public function test_the_visitors_real_address_is_used_behind_a_cloudflare_tunnel_only(): void
    {
        $attempt = fn (array $server, array $headers) => $this->withServerVariables($server)->withHeaders($headers)
            ->post('/login', ['login' => 'nobody', 'password' => 'x']);

        // Off by default: the header is ignored.
        $attempt(['REMOTE_ADDR' => '127.0.0.1'], ['CF-Connecting-IP' => '203.0.113.9']);
        $this->assertSame('127.0.0.1', AuditLog::latest('id')->first()->ip_address);

        config(['portal.behind_cloudflare' => true]);
        $attempt(['REMOTE_ADDR' => '127.0.0.1'], ['CF-Connecting-IP' => '203.0.113.9']);
        $this->assertSame('203.0.113.9', AuditLog::latest('id')->first()->ip_address);

        // A request that did not come from the local tunnel cannot spoof it.
        $attempt(['REMOTE_ADDR' => '198.51.100.20'], ['CF-Connecting-IP' => '203.0.113.50']);
        $this->assertSame('198.51.100.20', AuditLog::latest('id')->first()->ip_address);

        // Garbage values are ignored.
        $attempt(['REMOTE_ADDR' => '127.0.0.1'], ['CF-Connecting-IP' => 'not-an-ip']);
        $this->assertSame('127.0.0.1', AuditLog::latest('id')->first()->ip_address);
    }
}
