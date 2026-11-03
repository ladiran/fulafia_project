<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;
    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true, 'current_semester' => 1]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function on(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 10:00', 'Africa/Lagos'));
    }

    private function window(string $key, string $from, string $to, ?string $override = null): CalendarEvent
    {
        return CalendarEvent::create(['academic_session_id' => $this->session->id, 'key' => $key, 'title' => $key, 'category' => 'other',
            'starts_on' => $from, 'ends_on' => $to, 'override' => $override]);
    }

    private function student(): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT, 'identifier' => 'FUL/'.Str::random(7)])->id,
            'entry_mode' => 'regular', 'entry_level' => 100, 'gender' => 'male', 'current_level' => 100, 'status' => 'active',
        ]);
    }

    private function core(string $code = 'PHY101', int $semester = 1): Course
    {
        return Course::create(['code' => $code, 'title' => $code, 'units' => 3, 'level' => 100, 'semester' => $semester, 'type' => 'C']);
    }

    // ---- The official calendar ----

    public function test_the_official_calendar_loads_with_the_controlling_windows(): void
    {
        $this->actingAs($this->hod)->post("/admin/calendar/{$this->session->id}/load-official")->assertSessionHas('status');

        $reg = CalendarEvent::where('key', 'registration_1')->first();
        $this->assertSame('2026-11-09', $reg->starts_on->toDateString());
        $this->assertSame('2026-11-13', $reg->ends_on->toDateString());
        $this->assertSame('2027-04-09', CalendarEvent::where('key', 'registration_2')->first()->ends_on->toDateString());
        $this->assertSame('2027-04-02', CalendarEvent::where('key', 'results_1')->first()->ends_on->toDateString());
        $this->assertSame('2027-08-20', CalendarEvent::where('key', 'results_2')->first()->ends_on->toDateString());
        $this->assertSame('2027-04-05', CalendarEvent::where('key', 'semester_2_start')->first()->starts_on->toDateString());

        // It cannot be loaded twice.
        $this->actingAs($this->hod)->post("/admin/calendar/{$this->session->id}/load-official")->assertStatus(422);
    }

    public function test_the_hod_and_eo_can_read_it_but_only_the_hod_changes_it(): void
    {
        $this->actingAs($this->hod)->post("/admin/calendar/{$this->session->id}/load-official");
        $event = CalendarEvent::first();

        $this->actingAs($this->eo)->get('/admin/calendar')->assertOk()->assertSee('Registration of Students on Campus');
        $this->actingAs($this->eo)->post("/admin/calendar/{$this->session->id}/events", ['title' => 'X', 'category' => 'other', 'starts_on' => '2026-12-01'])->assertForbidden();
        $this->actingAs($this->eo)->put("/admin/calendar/events/{$event->id}", ['title' => 'X', 'category' => 'other', 'starts_on' => '2026-12-01'])->assertForbidden();
        $this->actingAs($this->eo)->delete("/admin/calendar/events/{$event->id}")->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LECTURER]))->get('/admin/calendar')->assertForbidden();
    }

    public function test_the_hod_can_add_move_and_delete_events(): void
    {
        $this->actingAs($this->hod)->post("/admin/calendar/{$this->session->id}/events", [
            'title' => 'Departmental retreat', 'category' => 'other', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02',
        ])->assertSessionHasNoErrors();
        $event = CalendarEvent::where('title', 'Departmental retreat')->firstOrFail();

        $this->actingAs($this->hod)->put("/admin/calendar/events/{$event->id}", ['title' => 'Departmental retreat', 'category' => 'other', 'starts_on' => '2026-12-03'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-03', $event->fresh()->starts_on->toDateString());

        // The end cannot come before the start.
        $this->actingAs($this->hod)->put("/admin/calendar/events/{$event->id}", ['title' => 'x', 'category' => 'other', 'starts_on' => '2026-12-03', 'ends_on' => '2026-12-01'])->assertSessionHasErrors('ends_on');

        $this->actingAs($this->hod)->delete("/admin/calendar/events/{$event->id}");
        $this->assertNull(CalendarEvent::find($event->id));
    }

    public function test_a_session_can_only_have_one_event_for_each_controlled_window(): void
    {
        $this->window('registration_1', '2026-11-09', '2026-11-13');

        $this->actingAs($this->hod)->post("/admin/calendar/{$this->session->id}/events", [
            'title' => 'Second', 'category' => 'registration', 'starts_on' => '2026-11-20', 'key' => 'registration_1',
        ])->assertSessionHasErrors('key');
    }

    public function test_a_new_session_can_start_from_an_earlier_calendar_shifted_by_whole_weeks(): void
    {
        $this->window('registration_1', '2026-11-09', '2026-11-13');
        $next = AcademicSession::create(['name' => '2027/2028']);

        $this->actingAs($this->hod)->post("/admin/calendar/{$next->id}/copy", ['from' => $this->session->id])->assertSessionHasNoErrors();

        $copy = CalendarEvent::where('academic_session_id', $next->id)->where('key', 'registration_1')->firstOrFail();
        $this->assertSame('2027-11-08', $copy->starts_on->toDateString()); // still a Monday
        $this->assertSame('Monday', $copy->starts_on->format('l'));
    }

    // ---- Registration ----

    public function test_students_can_register_only_inside_the_registration_window(): void
    {
        $student = $this->student();
        $core = $this->core();
        $this->window('registration_1', '2026-11-09', '2026-11-13');

        $this->on('2026-11-06');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertForbidden();
        $this->actingAs($student->user)->get('/student/registration')->assertSee('has not opened yet');

        $this->on('2026-11-09');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->on('2026-11-13');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertSessionHasNoErrors();

        $this->on('2026-11-14');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertForbidden();
        $this->actingAs($student->user)->get('/student/registration')->assertSee('closed on');
    }

    public function test_the_hod_can_open_a_closed_registration_and_close_an_open_one(): void
    {
        $student = $this->student();
        $core = $this->core();
        $event = $this->window('registration_1', '2026-11-09', '2026-11-13');
        $this->on('2026-11-20');

        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertForbidden();

        $this->actingAs($this->hod)->post("/admin/calendar/events/{$event->id}/override", ['value' => 'open'])->assertSessionHasNoErrors();
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertSessionHasNoErrors();

        $this->on('2026-11-10');
        $this->actingAs($this->hod)->post("/admin/calendar/events/{$event->id}/override", ['value' => 'closed']);
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertForbidden();

        $this->actingAs($this->hod)->post("/admin/calendar/events/{$event->id}/override", ['value' => 'dates']);
        $this->assertNull($event->fresh()->override);
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertSessionHasNoErrors();

        $this->actingAs($this->eo)->post("/admin/calendar/events/{$event->id}/override", ['value' => 'open'])->assertForbidden();
    }

    public function test_the_semesters_have_their_own_registration_windows(): void
    {
        $student = $this->student();
        $core2 = $this->core('PHY102', 2);
        $this->window('registration_1', '2026-11-09', '2026-11-13');
        $this->window('registration_2', '2027-04-05', '2027-04-09');
        $this->session->update(['current_semester' => 2]);

        $this->on('2026-11-10'); // first semester's window is open, but it is the second semester
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core2->id]])->assertForbidden();

        $this->on('2027-04-06');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core2->id]])->assertSessionHasNoErrors();
    }

    public function test_without_a_calendar_registration_is_not_restricted(): void
    {
        $student = $this->student();
        $core = $this->core();
        $this->on('2030-01-01');

        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertSessionHasNoErrors();
    }

    // ---- Result upload ----

    private function sheet(User $lecturer, int $semester = 1): CourseOffering
    {
        $course = $this->core('PHY1'.$semester.'1', $semester);

        return CourseOffering::create(['course_id' => $course->id, 'academic_session_id' => $this->session->id, 'lecturer_id' => $lecturer->id, 'status' => 'draft']);
    }

    public function test_lecturers_can_work_on_a_sheet_only_inside_the_result_window(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $offering = $this->sheet($lecturer);
        $this->window('results_1', '2027-02-15', '2027-04-02');

        $this->on('2027-02-01');
        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasErrors('scores');
        $this->actingAs($lecturer)->post("/results/{$offering->id}/submit")->assertSessionHasErrors('scores');
        $this->actingAs($lecturer)->get("/results/{$offering->id}")->assertOk()->assertSee('locked by the academic calendar');

        $this->on('2027-02-15');
        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasNoErrors();
        $this->actingAs($lecturer)->get("/results/{$offering->id}")->assertDontSee('locked by the academic calendar');

        $this->on('2027-04-03');
        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasErrors('scores');
    }

    public function test_the_eo_and_hod_are_not_held_to_the_result_window_and_the_hod_can_reopen_it_for_lecturers(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $offering = $this->sheet($lecturer);
        $event = $this->window('results_1', '2027-02-15', '2027-04-02');
        $this->on('2027-05-01');

        $this->actingAs($this->eo)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasNoErrors();
        $this->actingAs($this->hod)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasNoErrors();
        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasErrors('scores');

        $this->actingAs($this->hod)->post("/admin/calendar/events/{$event->id}/override", ['value' => 'open']);
        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasNoErrors();
    }

    public function test_the_second_semester_sheet_follows_the_second_semester_window(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $offering = $this->sheet($lecturer, 2);
        $this->window('results_1', '2027-02-15', '2027-04-02');
        $this->window('results_2', '2027-07-12', '2027-08-20');

        $this->on('2027-03-01'); // first semester window, wrong semester
        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasErrors('scores');

        $this->on('2027-07-20');
        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => []])->assertSessionHasNoErrors();
    }

    // ---- Automatic semester switch and reminders ----

    public function test_the_portal_moves_to_the_second_semester_on_the_calendar_date(): void
    {
        $this->window('semester_2_start', '2027-04-05', '2027-04-05');

        $this->on('2027-04-04');
        $this->artisan('calendar:daily')->assertSuccessful();
        $this->assertSame(1, (int) $this->session->fresh()->current_semester);

        $this->on('2027-04-05');
        $this->artisan('calendar:daily')->assertSuccessful();
        $this->assertSame(2, (int) $this->session->fresh()->current_semester);
    }

    public function test_students_are_reminded_once_before_registration_closes(): void
    {
        $student = $this->student();
        $this->window('registration_1', '2026-11-09', '2026-11-13');

        $this->on('2026-11-09'); // four days left: too early
        $this->assertSame(0, app(CalendarService::class)->sendReminders());

        $this->on('2026-11-11'); // two days left
        $this->assertSame(1, app(CalendarService::class)->sendReminders());
        $this->assertSame(1, $student->user->notifications()->count());
        $this->assertStringContainsString('Friday 13 November 2026', $student->user->notifications()->first()->data['message']);
        $this->assertSame(1, $this->hod->notifications()->count());

        $this->on('2026-11-12'); // not again
        $this->assertSame(0, app(CalendarService::class)->sendReminders());
        $this->assertSame(1, $student->user->notifications()->count());
    }

    public function test_lecturers_with_unfinished_sheets_are_reminded_before_the_result_window_closes(): void
    {
        $busy = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $done = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $this->sheet($busy);
        $other = $this->core('PHY199');
        CourseOffering::create(['course_id' => $other->id, 'academic_session_id' => $this->session->id, 'lecturer_id' => $done->id, 'status' => 'submitted']);
        $this->window('results_1', '2027-02-15', '2027-04-02');

        $this->on('2027-03-31');
        $this->assertSame(1, app(CalendarService::class)->sendReminders());
        $this->assertSame(1, $busy->notifications()->count());
        $this->assertSame(0, $done->notifications()->count());
    }

    // ---- The public page ----

    public function test_the_public_calendar_shows_the_session_dates_without_faculty_activities(): void
    {
        $this->actingAs($this->hod)->post("/admin/calendar/{$this->session->id}/load-official");

        $this->get('/academic-calendar')->assertOk()
            ->assertSee('Commencement of 1st Semester Lectures')
            ->assertSee('Senate Meeting for Approval of 2nd Semester Results')
            ->assertDontSee('Academic Activities in Faculty of Arts');
    }

    public function test_the_calendar_page_renders_empty_and_in_edit_mode(): void
    {
        $this->actingAs($this->hod)->get('/admin/calendar')->assertOk()
            ->assertSee('No calendar for 2026/2027 yet')->assertSee('Load the official 2026/2027 calendar');

        $event = $this->window('registration_1', '2026-11-09', '2026-11-13');
        $this->actingAs($this->hod)->get("/admin/calendar?session={$this->session->id}&edit={$event->id}")->assertOk()
            ->assertSee('Edit event')->assertSee('Controls the portal');
    }
}
