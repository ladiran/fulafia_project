<?php

namespace Tests\Feature;

use App\Models\LevelAdviserAppointment;
use App\Models\User;
use App\Notifications\AdviserTenureEnding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LevelAdviserTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function appointment(User $lecturer, User $hod, string $endsOn, int $level = 100): LevelAdviserAppointment
    {
        return LevelAdviserAppointment::create([
            'user_id' => $lecturer->id,
            'level' => $level,
            'starts_on' => now()->subYears(2)->toDateString(),
            'ends_on' => $endsOn,
            'appointed_by' => $hod->id,
        ]);
    }

    public function test_only_the_hod_can_manage_level_advisers(): void
    {
        $eo = $this->user(User::ROLE_SECONDARY_ADMIN);
        $lecturer = $this->user(User::ROLE_LECTURER);

        $this->actingAs($eo)->get('/admin/level-advisers')->assertForbidden();
        $this->actingAs($eo)->post('/admin/level-advisers', ['user_id' => $lecturer->id, 'level' => 100])->assertForbidden();
        $this->actingAs($lecturer)->get('/admin/level-advisers')->assertForbidden();
    }

    public function test_hod_appoints_for_two_years_and_a_level_has_one_adviser(): void
    {
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $a = $this->user(User::ROLE_LECTURER);
        $b = $this->user(User::ROLE_LECTURER);

        $this->actingAs($hod)->post('/admin/level-advisers', ['user_id' => $a->id, 'level' => 200])->assertSessionHasNoErrors();

        $appointment = LevelAdviserAppointment::firstOrFail();
        $this->assertTrue($appointment->ends_on->isSameDay(today()->addYears(2)));

        $this->actingAs($hod)->post('/admin/level-advisers', ['user_id' => $b->id, 'level' => 200])->assertSessionHasErrors('level');
        $this->assertSame(1, LevelAdviserAppointment::count());
    }

    public function test_reminder_goes_to_adviser_and_hod_two_months_before_expiry_only_once(): void
    {
        Notification::fake();
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $lecturer = $this->user(User::ROLE_LECTURER);
        $this->appointment($lecturer, $hod, now()->addMonths(2)->toDateString());

        $this->artisan('advisers:send-expiry-reminders')->assertSuccessful();
        $this->artisan('advisers:send-expiry-reminders')->assertSuccessful();

        Notification::assertSentToTimes($lecturer, AdviserTenureEnding::class, 1);
        Notification::assertSentToTimes($hod, AdviserTenureEnding::class, 1);
    }

    public function test_no_reminder_before_the_two_month_window(): void
    {
        Notification::fake();
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $lecturer = $this->user(User::ROLE_LECTURER);
        $this->appointment($lecturer, $hod, now()->addMonths(3)->toDateString());

        $this->artisan('advisers:send-expiry-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_expired_adviser_stays_in_post_and_only_the_hod_can_remove_them(): void
    {
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $eo = $this->user(User::ROLE_SECONDARY_ADMIN);
        $lecturer = $this->user(User::ROLE_LECTURER);
        $appointment = $this->appointment($lecturer, $hod, now()->subDay()->toDateString());

        $this->artisan('advisers:send-expiry-reminders')->assertSuccessful();
        $this->assertSame('EXPIRED', $appointment->fresh()->status());
        $this->assertNull($appointment->fresh()->removed_at);

        $this->actingAs($lecturer)->delete("/admin/level-advisers/{$appointment->id}")->assertForbidden();
        $this->actingAs($eo)->delete("/admin/level-advisers/{$appointment->id}")->assertForbidden();
        $this->assertNull($appointment->fresh()->removed_at);

        $this->actingAs($hod)->delete("/admin/level-advisers/{$appointment->id}")->assertSessionHasNoErrors();
        $this->assertSame('REMOVED', $appointment->fresh()->status());
    }

    public function test_banner_shows_to_the_hod_and_the_adviser(): void
    {
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $lecturer = $this->user(User::ROLE_LECTURER);
        $this->appointment($lecturer, $hod, now()->addMonth()->toDateString(), 300);

        $this->actingAs($hod)->get('/admin')->assertSee('tenure ending soon');
        $this->actingAs($lecturer)->get('/staff')->assertSee('Your tenure as 300L Level Adviser');
    }
}
