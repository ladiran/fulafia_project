<?php

namespace Tests\Feature;

use App\Models\LevelAdviserAppointment;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class StaffAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-7';

    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $this->eo = $this->user(User::ROLE_SECONDARY_ADMIN);
    }

    private function user(string $role, array $extra = []): User
    {
        // $extra wins over the defaults (array "+" keeps the left-hand value, so it goes first).
        return User::factory()->create($extra + ['role' => $role, 'password' => self::PASSWORD, 'identifier' => 'STAFF-'.\Illuminate\Support\Str::random(6)]);
    }

    private function newStaff(array $override = []): array
    {
        return $override + ['name' => 'Dr. Ada Obi', 'email' => 'ada@example.com', 'identifier' => 'STAFF-100', 'add_to_directory' => '1'];
    }

    private function tempPassword($response): string
    {
        preg_match('/\(shown once\): (\S+)/', $response->getSession()->get('status'), $m);

        return $m[1] ?? '';
    }

    // ---- Access ----

    public function test_only_the_hod_and_eo_open_the_staff_accounts_page(): void
    {
        $this->actingAs($this->hod)->get('/admin/accounts')->assertOk();
        $this->actingAs($this->eo)->get('/admin/accounts')->assertOk();
        $this->actingAs($this->user(User::ROLE_ADMIN, ['permissions' => ['manage_students', 'manage_courses', 'manage_content']]))->get('/admin/accounts')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_LECTURER))->get('/admin/accounts')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_STUDENT))->get('/admin/accounts')->assertForbidden();
    }

    // ---- Creating ----

    public function test_a_new_lecturer_gets_a_temporary_password_and_must_change_it(): void
    {
        $response = $this->actingAs($this->eo)->post('/admin/accounts', $this->newStaff())->assertSessionHasNoErrors();
        $password = $this->tempPassword($response);
        $this->assertNotEmpty($password);

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_LECTURER, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($password, $user->password));

        auth()->logout();
        $this->post('/login', ['login' => 'STAFF-100', 'password' => $password])->assertRedirect();
        $this->get('/staff')->assertRedirect(route('security.password'));
    }

    public function test_accounts_are_always_created_as_lecturers_whatever_is_sent(): void
    {
        $this->actingAs($this->eo)->post('/admin/accounts', $this->newStaff(['role' => 'principal_admin', 'permissions' => ['manage_courses']]));

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_LECTURER, $user->role);
        $this->assertNull($user->permissions);
    }

    public function test_email_and_staff_id_must_be_unique_and_valid(): void
    {
        $this->actingAs($this->eo)->post('/admin/accounts', $this->newStaff())->assertSessionHasNoErrors();

        $this->actingAs($this->eo)->post('/admin/accounts', $this->newStaff(['identifier' => 'STAFF-101']))->assertSessionHasErrors('email');
        $this->actingAs($this->eo)->post('/admin/accounts', $this->newStaff(['email' => 'other@example.com']))->assertSessionHasErrors('identifier');
        $this->actingAs($this->eo)->post('/admin/accounts', $this->newStaff(['email' => 'not-an-email', 'identifier' => 'X']))->assertSessionHasErrors('email');
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
    }

    public function test_a_public_directory_entry_is_created_only_when_asked(): void
    {
        $this->actingAs($this->eo)->post('/admin/accounts', $this->newStaff(['title' => 'Dr.', 'position' => 'Lecturer I']));
        $member = StaffMember::firstOrFail();
        $this->assertSame(User::where('email', 'ada@example.com')->value('id'), $member->user_id);
        $this->assertSame('Lecturer I', $member->position);

        $this->actingAs($this->eo)->post('/admin/accounts', ['add_to_directory' => null] + $this->newStaff(['email' => 'b@example.com', 'identifier' => 'STAFF-200']));
        $this->assertSame(1, StaffMember::count());
    }

    // ---- Who can manage whom ----

    public function test_the_eo_manages_lecturers_only_and_the_hod_manages_everyone_but_the_hod(): void
    {
        $lecturer = $this->user(User::ROLE_LECTURER);
        $admin = $this->user(User::ROLE_ADMIN, ['permissions' => ['manage_content']]);
        $payload = fn (User $u) => ['name' => 'Renamed', 'email' => $u->email, 'identifier' => $u->identifier];

        $this->actingAs($this->eo)->put("/admin/accounts/{$lecturer->id}", $payload($lecturer))->assertSessionHasNoErrors();
        $this->actingAs($this->eo)->put("/admin/accounts/{$admin->id}", $payload($admin))->assertForbidden();
        $this->actingAs($this->eo)->put("/admin/accounts/{$this->hod->id}", $payload($this->hod))->assertForbidden();
        $this->actingAs($this->eo)->put("/admin/accounts/{$this->eo->id}", $payload($this->eo))->assertForbidden();

        $this->actingAs($this->hod)->put("/admin/accounts/{$admin->id}", $payload($admin))->assertSessionHasNoErrors();
        $this->actingAs($this->hod)->put("/admin/accounts/{$this->eo->id}", $payload($this->eo))->assertSessionHasNoErrors();
        $this->actingAs($this->hod)->put("/admin/accounts/{$this->hod->id}", $payload($this->hod))->assertForbidden();
        $this->assertSame('Renamed', $lecturer->fresh()->name);
    }

    public function test_students_cannot_be_managed_here(): void
    {
        $student = $this->user(User::ROLE_STUDENT);

        $this->actingAs($this->hod)->post("/admin/accounts/{$student->id}/deactivate")->assertForbidden();
        $this->actingAs($this->hod)->post("/admin/accounts/{$student->id}/reset-password")->assertForbidden();
    }

    // ---- Deactivate / reactivate / reset ----

    public function test_deactivating_stops_sign_in_and_reactivating_restores_it(): void
    {
        $lecturer = $this->user(User::ROLE_LECTURER, ['identifier' => 'STAFF-300']);

        $this->actingAs($this->eo)->post("/admin/accounts/{$lecturer->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($lecturer->fresh()->is_active);

        auth()->logout();
        $this->post('/login', ['login' => 'STAFF-300', 'password' => self::PASSWORD])->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->actingAs($this->eo)->post("/admin/accounts/{$lecturer->id}/reactivate");
        auth()->logout();
        $this->post('/login', ['login' => 'STAFF-300', 'password' => self::PASSWORD])->assertRedirect();
        $this->get('/staff')->assertOk();
    }

    public function test_a_level_adviser_must_be_removed_from_the_post_before_deactivation(): void
    {
        $lecturer = $this->user(User::ROLE_LECTURER);
        $appointment = LevelAdviserAppointment::create([
            'user_id' => $lecturer->id, 'level' => 100, 'starts_on' => today(), 'ends_on' => today()->addYears(2), 'appointed_by' => $this->hod->id,
        ]);

        $this->actingAs($this->hod)->post("/admin/accounts/{$lecturer->id}/deactivate")->assertSessionHasErrors('account');
        $this->assertTrue($lecturer->fresh()->is_active);

        $appointment->update(['removed_at' => now(), 'removed_by' => $this->hod->id]);
        $this->actingAs($this->hod)->post("/admin/accounts/{$lecturer->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($lecturer->fresh()->is_active);
    }

    public function test_resetting_a_password_forces_a_change_and_the_old_one_stops_working(): void
    {
        $lecturer = $this->user(User::ROLE_LECTURER);

        $response = $this->actingAs($this->eo)->post("/admin/accounts/{$lecturer->id}/reset-password");
        $new = $this->tempPassword($response);

        $lecturer->refresh();
        $this->assertTrue($lecturer->must_change_password);
        $this->assertFalse(Hash::check(self::PASSWORD, $lecturer->password));
        $this->assertTrue(Hash::check($new, $lecturer->password));
    }

    // ---- Examination Officer ----

    public function test_only_the_hod_appoints_the_eo_and_there_is_only_ever_one(): void
    {
        $a = $this->user(User::ROLE_LECTURER);
        $b = $this->user(User::ROLE_ADMIN, ['permissions' => ['manage_content']]);

        $this->actingAs($this->eo)->post('/admin/accounts/appoint-eo', ['user_id' => $a->id])->assertForbidden();
        $this->actingAs($this->user(User::ROLE_LECTURER))->post('/admin/accounts/appoint-eo', ['user_id' => $a->id])->assertForbidden();

        $this->actingAs($this->hod)->post('/admin/accounts/appoint-eo', ['user_id' => $a->id])->assertSessionHasNoErrors();
        $this->assertSame(User::ROLE_SECONDARY_ADMIN, $a->fresh()->role);
        $this->assertSame(User::ROLE_LECTURER, $this->eo->fresh()->role, 'the previous EO is a lecturer again');

        $this->actingAs($this->hod)->post('/admin/accounts/appoint-eo', ['user_id' => $b->id]);
        $this->assertSame(1, User::where('role', User::ROLE_SECONDARY_ADMIN)->count());
        $this->assertNull($b->fresh()->permissions);
    }

    public function test_the_eo_cannot_be_chosen_from_students_inactive_staff_or_the_hod(): void
    {
        $inactive = $this->user(User::ROLE_LECTURER, ['is_active' => false]);
        $student = $this->user(User::ROLE_STUDENT);

        $this->actingAs($this->hod)->post('/admin/accounts/appoint-eo', ['user_id' => $inactive->id])->assertSessionHasErrors('user_id');
        $this->actingAs($this->hod)->post('/admin/accounts/appoint-eo', ['user_id' => $student->id])->assertSessionHasErrors('user_id');
        $this->actingAs($this->hod)->post('/admin/accounts/appoint-eo', ['user_id' => $this->hod->id])->assertSessionHasErrors('user_id');
        $this->assertSame(User::ROLE_SECONDARY_ADMIN, $this->eo->fresh()->role);
    }

    // ---- Office of HoD ----

    public function test_handover_moves_the_office_and_leaves_exactly_one_hod(): void
    {
        $successor = $this->user(User::ROLE_LECTURER);
        $url = '/admin/accounts/handover';
        $good = ['new_hod_id' => $successor->id, 'current_password' => self::PASSWORD, 'confirm' => '1'];

        $this->actingAs($this->eo)->post($url, $good)->assertForbidden();
        $this->actingAs($this->hod)->post($url, ['current_password' => 'wrong'] + $good)->assertSessionHasErrors('current_password');
        $this->actingAs($this->hod)->post($url, ['confirm' => null] + $good)->assertSessionHasErrors('confirm');
        $this->assertSame(User::ROLE_PRINCIPAL_ADMIN, $this->hod->fresh()->role);

        $this->actingAs($this->hod)->post($url, $good)->assertRedirect('/');

        $this->assertSame(User::ROLE_PRINCIPAL_ADMIN, $successor->fresh()->role);
        $this->assertSame(User::ROLE_LECTURER, $this->hod->fresh()->role);
        $this->assertSame(1, User::where('role', User::ROLE_PRINCIPAL_ADMIN)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'office.hod_handover']);

        // The former HoD has no admin rights left.
        $this->actingAs($this->hod->fresh())->get('/admin')->assertForbidden();
        $this->actingAs($successor->fresh())->get('/admin')->assertOk();
    }

    public function test_the_new_hod_can_be_the_eo_and_the_eo_seat_becomes_free(): void
    {
        $this->actingAs($this->hod)->post('/admin/accounts/handover', ['new_hod_id' => $this->eo->id, 'current_password' => self::PASSWORD, 'confirm' => '1'])->assertRedirect('/');

        $this->assertSame(User::ROLE_PRINCIPAL_ADMIN, $this->eo->fresh()->role);
        $this->assertSame(0, User::where('role', User::ROLE_SECONDARY_ADMIN)->count());
    }

    public function test_handover_refuses_students_and_inactive_accounts(): void
    {
        foreach ([$this->user(User::ROLE_STUDENT), $this->user(User::ROLE_LECTURER, ['is_active' => false])] as $target) {
            $this->actingAs($this->hod)->post('/admin/accounts/handover', ['new_hod_id' => $target->id, 'current_password' => self::PASSWORD, 'confirm' => '1'])
                ->assertSessionHasErrors('new_hod_id');
        }
        $this->assertSame(User::ROLE_PRINCIPAL_ADMIN, $this->hod->fresh()->role);
    }

    public function test_a_new_hod_must_set_up_two_factor_before_using_the_portal(): void
    {
        config(['portal.require_admin_2fa' => true]);
        $successor = $this->user(User::ROLE_LECTURER);
        $this->hod->forceFill(['two_factor_secret' => 'ABCDEFGHIJKLMNOP', 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($this->hod->fresh())->post('/admin/accounts/handover', ['new_hod_id' => $successor->id, 'current_password' => self::PASSWORD, 'confirm' => '1']);

        $this->actingAs($successor->fresh())->get('/admin')->assertRedirect(route('security.two-factor'));
    }

    // ---- Forgot password by email ----

    public function test_staff_get_a_reset_link_and_the_answer_does_not_reveal_whether_an_email_exists(): void
    {
        Notification::fake();
        $lecturer = $this->user(User::ROLE_LECTURER, ['email' => 'lect@example.com']);
        $student = $this->user(User::ROLE_STUDENT, ['email' => 'stud@example.com']);
        $inactive = $this->user(User::ROLE_LECTURER, ['email' => 'gone@example.com', 'is_active' => false]);

        $known = $this->post('/forgot-password', ['email' => 'lect@example.com']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);
        $this->post('/forgot-password', ['email' => 'stud@example.com']);
        $this->post('/forgot-password', ['email' => 'gone@example.com']);

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        Notification::assertSentTo($lecturer, ResetPassword::class);
        Notification::assertNotSentTo($student, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_a_valid_link_sets_a_new_password_that_follows_the_rules(): void
    {
        $lecturer = $this->user(User::ROLE_LECTURER, ['email' => 'lect@example.com', 'identifier' => 'STAFF-400', 'must_change_password' => true]);
        $token = Password::createToken($lecturer);
        $base = ['token' => $token, 'email' => 'lect@example.com'];

        $this->post('/reset-password', $base + ['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post('/reset-password', $base + ['password' => 'Brand-New-Pass9', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => 'bad-token'] + $base + ['password' => 'Brand-New-Pass9', 'password_confirmation' => 'Brand-New-Pass9'])->assertSessionHasErrors('email');

        $this->post('/reset-password', $base + ['password' => 'Brand-New-Pass9', 'password_confirmation' => 'Brand-New-Pass9'])->assertRedirect(route('login'));

        $lecturer->refresh();
        $this->assertTrue(Hash::check('Brand-New-Pass9', $lecturer->password));
        $this->assertFalse($lecturer->must_change_password);
        $this->post('/login', ['login' => 'STAFF-400', 'password' => 'Brand-New-Pass9'])->assertRedirect();
        $this->get('/staff')->assertOk();

        // A link works once.
        auth()->logout();
        $this->post('/reset-password', $base + ['password' => 'Another-Pass-77', 'password_confirmation' => 'Another-Pass-77'])->assertSessionHasErrors('email');
    }

    public function test_a_reset_link_never_works_for_a_student(): void
    {
        $student = $this->user(User::ROLE_STUDENT, ['email' => 'stud@example.com']);
        $token = Password::createToken($student);

        $this->post('/reset-password', ['token' => $token, 'email' => 'stud@example.com', 'password' => 'Brand-New-Pass9', 'password_confirmation' => 'Brand-New-Pass9'])->assertForbidden();
        $this->assertTrue(Hash::check(self::PASSWORD, $student->fresh()->password));
    }

    public function test_resetting_by_email_does_not_remove_the_two_factor_requirement(): void
    {
        $hod = $this->hod;
        $service = app(\App\Services\TwoFactorService::class);
        $hod->forceFill(['two_factor_secret' => $service->newSecret()])->save();
        $service->enable($hod);
        $token = Password::createToken($hod);

        $this->post('/reset-password', ['token' => $token, 'email' => $hod->email, 'password' => 'Brand-New-Pass9', 'password_confirmation' => 'Brand-New-Pass9'])->assertRedirect(route('login'));
        $this->post('/login', ['login' => $hod->email, 'password' => 'Brand-New-Pass9'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_the_forgot_password_page_is_linked_from_login(): void
    {
        $this->get('/login')->assertSee('Forgot password?');
        $this->get('/forgot-password')->assertOk()->assertSee('Send reset link');
    }
}
