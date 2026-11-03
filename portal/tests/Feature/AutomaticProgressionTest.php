<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\GraduationBatch;
use App\Models\ResidencyExtension;
use App\Models\Result;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\GradingService;
use App\Services\RegistrationService;
use App\Services\SessionRolloverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticProgressionTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $s1;
    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s1 = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 2, 'specialization_window_open' => true]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
    }

    private function student(int $level, string $status = 'active', ?AcademicSession $entry = null, array $extra = []): StudentProfile
    {
        return StudentProfile::create($extra + [
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT, 'identifier' => 'FUL/'.Str::random(7)])->id,
            'entry_mode' => 'regular', 'entry_level' => 100, 'gender' => 'male', 'current_level' => $level, 'status' => $status,
            'entry_session_id' => $entry?->id,
        ]);
    }

    private function next(string $name = '2026/2027', ?User $by = null)
    {
        return $this->actingAs($by ?? $this->eo)->post('/admin/sessions/start-next', ['name' => $name]);
    }

    private function course(string $code, int $units = 3, int $level = 200, int $semester = 1, string $type = 'C'): Course
    {
        return Course::create(['code' => $code, 'title' => $code, 'units' => $units, 'level' => $level, 'semester' => $semester, 'type' => $type]);
    }

    // ---- Starting the next session moves students up ----

    public function test_only_the_hod_and_eo_can_start_the_next_session(): void
    {
        foreach ([User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students', 'manage_courses', 'manage_results']]),
            User::factory()->create(['role' => User::ROLE_LECTURER]), User::factory()->create(['role' => User::ROLE_STUDENT])] as $no) {
            $this->next('2026/2027', $no)->assertForbidden();
        }
        $this->assertTrue($this->s1->fresh()->is_current);

        $this->next('2026/2027', $this->hod)->assertSessionHasNoErrors();
        $this->assertSame('2026/2027', AcademicSession::current()->name);
    }

    public function test_active_students_move_up_one_level_automatically_with_no_approval(): void
    {
        $a = $this->student(100);
        $b = $this->student(200);
        $c = $this->student(300);

        $this->next()->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame(200, $a->fresh()->current_level);
        $this->assertSame(300, $b->fresh()->current_level);
        $this->assertSame(400, $c->fresh()->current_level);
        $this->assertDatabaseHas('student_level_history', ['student_profile_id' => $a->id, 'from_level' => 100, 'to_level' => 200, 'outcome' => 'promoted', 'decided_by' => $this->eo->id]);
        $this->assertNotNull($this->s1->fresh()->rolled_over_at);
    }

    public function test_the_new_session_starts_fresh_and_the_old_one_closes(): void
    {
        $this->next();

        $new = AcademicSession::current();
        $this->assertSame('2026/2027', $new->name);
        $this->assertSame(1, (int) $new->current_semester);
        $this->assertFalse($new->specialization_window_open);
        $this->assertFalse($this->s1->fresh()->is_current);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.started']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.rolled_over']);
    }

    public function test_students_who_are_not_active_stay_where_they_are(): void
    {
        $others = collect(['deferred', 'suspended', 'withdrawn', 'graduated'])->map(fn ($status) => $this->student(200, $status));

        $this->next();

        foreach ($others as $s) {
            $this->assertSame(200, $s->fresh()->current_level, $s->status);
            $this->assertSame($s->status, $s->fresh()->status);
        }
        $this->assertSame(0, \DB::table('student_level_history')->count());
    }

    public function test_a_student_admitted_for_the_next_session_is_not_moved_up_early(): void
    {
        $later = AcademicSession::create(['name' => '2026/2027']);
        $admittedAhead = $this->student(100, entry: $later);
        $current = $this->student(100, entry: $this->s1);

        $this->next();

        $this->assertSame(100, $admittedAhead->fresh()->current_level);
        $this->assertSame(200, $current->fresh()->current_level);
    }

    public function test_a_session_is_only_ever_rolled_over_once_and_the_next_must_come_later(): void
    {
        $student = $this->student(100);

        $this->next('2025/2026')->assertSessionHasErrors('name');   // not after the current one
        $this->next('2024/2025')->assertSessionHasErrors('name');
        $this->next('2026-27')->assertSessionHasErrors('name');     // wrong format
        $this->assertSame(100, $student->fresh()->level ?? $student->fresh()->current_level);

        $this->next('2026/2027')->assertSessionHasNoErrors();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SessionRolloverService::class)->rollover($this->s1->fresh(), $this->eo);
    }

    public function test_an_undecided_graduation_batch_blocks_the_next_session(): void
    {
        $student = $this->student(400);
        $batch = GraduationBatch::create(['academic_session_id' => $this->s1->id, 'status' => 'proposed']);
        $batch->students()->attach($student->id);

        $this->next()->assertSessionHasErrors('name');
        $this->assertTrue($this->s1->fresh()->is_current);
        $this->assertSame(0, \DB::table('student_level_history')->count());

        $batch->update(['status' => 'rejected']);
        $this->next()->assertSessionHasNoErrors();
    }

    public function test_the_sessions_page_previews_what_will_happen(): void
    {
        $this->student(100);
        $this->student(200);
        $this->student(400);

        $this->actingAs($this->eo)->get('/admin/sessions')->assertOk()
            ->assertSee('Start the next session')->assertSee('students move up automatically')->assertSee('2026/2027');
        $preview = app(SessionRolloverService::class)->preview($this->s1);
        $this->assertSame(2, $preview['moving']);
        $this->assertSame(['100' => 1, '200' => 1], array_map('intval', $preview['by_level']));
        $this->assertSame(1, $preview['staying_at_400']);
        $this->assertSame(0, $preview['to_withdraw']);
    }

    public function test_the_first_session_becomes_current_and_nothing_moves_then(): void
    {
        AcademicSession::query()->delete();
        $student = $this->student(100);

        $this->actingAs($this->eo)->post('/admin/sessions', ['name' => '2025/2026'])->assertSessionHasNoErrors();

        $this->assertTrue(AcademicSession::firstOrFail()->is_current);
        $this->assertSame(100, $student->fresh()->current_level);
    }

    public function test_a_session_cannot_be_made_current_by_the_checkbox_once_one_is_running(): void
    {
        $later = AcademicSession::create(['name' => '2026/2027']);

        $this->actingAs($this->eo)->post("/admin/sessions/{$later->id}", ['current_semester' => 1, 'make_current' => '1'])->assertSessionHasNoErrors();

        $this->assertFalse($later->fresh()->is_current, 'moving to a new session only happens through "Start the next session", which moves students up');
        $this->assertTrue($this->s1->fresh()->is_current);
    }

    public function test_the_promotion_pages_are_gone(): void
    {
        $this->actingAs($this->hod)->get('/admin/promotion')->assertNotFound();
        $this->actingAs($this->hod)->get('/admin/sessions')->assertDontSee('Promotion');
        $this->assertFalse(\Schema::hasTable('promotion_batches'));
    }

    // ---- Failed courses travel with the student ----

    public function test_failed_courses_come_along_and_must_be_registered_again_next_session(): void
    {
        $failed = $this->course('PHY 101', 3, 100, 1);
        $passed = $this->course('PHY 102', 3, 100, 1);
        $core200 = $this->course('PHY 201', 3, 200, 1);
        $student = $this->student(100);
        $first = AcademicSession::create(['name' => '2024/2025']);
        foreach ([[$failed, 30], [$passed, 70]] as [$course, $score]) {
            $reg = CourseRegistration::create(['student_profile_id' => $student->id, 'course_id' => $course->id, 'academic_session_id' => $first->id, 'semester' => 1, 'status' => 'approved']);
            $offering = CourseOffering::create(['course_id' => $course->id, 'academic_session_id' => $first->id, 'status' => 'published']);
            Result::create(['course_registration_id' => $reg->id, 'course_offering_id' => $offering->id, 'ca' => 10, 'exam' => $score - 10] + app(GradingService::class)->evaluate(10, $score - 10, null));
        }

        $this->next();

        $this->assertSame(200, $student->fresh()->current_level);
        $available = app(RegistrationService::class)->availableCourses($student->fresh(), AcademicSession::current());
        $carry = $available->firstWhere('id', $failed->id);
        $this->assertTrue($carry->carry_over && $carry->mandatory, 'the failed course is compulsory again');
        $this->assertFalse($available->contains('id', $passed->id), 'passed courses are not repeated');
        $this->assertTrue($available->firstWhere('id', $core200->id)->mandatory);
        $this->actingAs($student->user->fresh())->post('/student/registration', ['courses' => [$core200->id]])->assertSessionHasErrors('courses');
        $this->actingAs($student->user->fresh())->post('/student/registration', ['courses' => [$core200->id, $failed->id]])->assertSessionHasNoErrors();
    }

    // ---- 24 units a semester ----

    public function test_no_more_than_24_units_and_failed_courses_take_priority(): void
    {
        $session = AcademicSession::current();
        $session->update(['current_semester' => 1]);
        $old = AcademicSession::create(['name' => '2024/2025']);
        $student = $this->student(300);
        // Two failed 100L courses of 6 units each (12 units) and four 4-unit core courses at 300L (16 units): 28 in all.
        foreach (['PHY 101', 'PHY 102'] as $code) {
            $c = $this->course($code, 6, 100, 1);
            $reg = CourseRegistration::create(['student_profile_id' => $student->id, 'course_id' => $c->id, 'academic_session_id' => $old->id, 'semester' => 1, 'status' => 'approved']);
            $off = CourseOffering::create(['course_id' => $c->id, 'academic_session_id' => $old->id, 'status' => 'published']);
            Result::create(['course_registration_id' => $reg->id, 'course_offering_id' => $off->id, 'ca' => 5, 'exam' => 10] + app(GradingService::class)->evaluate(5, 10, null));
        }
        $core = collect(['PHY 301', 'PHY 302', 'PHY 303', 'PHY 304'])->map(fn ($code) => $this->course($code, 4, 300, 1));

        $available = app(RegistrationService::class)->availableCourses($student, $session);

        $required = $available->where('mandatory', true);
        $this->assertSame(24, $required->sum('units'));
        $this->assertSame(['PHY 101', 'PHY 102'], $required->where('carry_over', true)->pluck('code')->sort()->values()->all(), 'carry-overs first');
        $over = $available->where('over_cap', true);
        $this->assertSame(['PHY 304'], $over->pluck('code')->all(), 'the last required course that does not fit waits for a later semester');
        $this->assertFalse($over->first()->mandatory);

        $ids = $required->pluck('id')->all();
        $this->actingAs($student->user)->post('/student/registration', ['courses' => $ids])->assertSessionHasNoErrors();
        $this->assertSame(24, CourseRegistration::where('academic_session_id', $session->id)->get()->sum(fn ($r) => $r->course->units));

        $this->actingAs($student->user)->post('/student/registration', ['courses' => array_merge($ids, [$over->first()->id])])
            ->assertSessionHasErrors('courses');
        $this->actingAs($student->user)->get('/student/registration')->assertSee('over the 24-unit limit');
    }

    public function test_optional_courses_cannot_push_the_total_over_24_and_approved_units_count(): void
    {
        $session = AcademicSession::current();
        $session->update(['current_semester' => 1]);
        $student = $this->student(200);
        $core = $this->course('PHY 201', 12, 200, 1);
        $core2 = $this->course('PHY 202', 8, 200, 1);
        $e1 = $this->course('CSC 201', 4, 200, 1, 'E');
        $e2 = $this->course('STA 201', 3, 200, 1, 'E');

        // 20 required + 4 optional = 24: fine. 20 + 4 + 3 = 27: refused.
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id, $core2->id, $e1->id, $e2->id]])->assertSessionHasErrors('courses');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id, $core2->id, $e1->id]])->assertSessionHasNoErrors();
        $this->assertSame(24, CourseRegistration::where('student_profile_id', $student->id)->get()->sum(fn ($r) => $r->course->units));

        // Approved registrations are locked and still use their share of the 24.
        CourseRegistration::query()->update(['status' => 'approved']);
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$e2->id]])->assertSessionHasErrors('courses');
    }

    // ---- Residency at 400 level ----

    public function test_a_400_level_student_who_has_not_graduated_stays_for_two_more_sessions_then_is_withdrawn(): void
    {
        $student = $this->student(400, entry: $this->s1);
        $names = ['2026/2027', '2027/2028', '2028/2029'];

        $this->next($names[0]);   // finished the 1st session at 400 level
        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame(1, $student->fresh()->sessions_at_400);
        $this->assertDatabaseHas('student_level_history', ['student_profile_id' => $student->id, 'outcome' => 'stayed']);

        $this->next($names[1]);   // 2nd
        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame(1, $student->fresh()->sessionsAt400Left());

        $this->next($names[2]);   // 3rd: all allowed sessions used
        $student->refresh();
        $this->assertSame('withdrawn', $student->status);
        $this->assertSame('residency_exceeded', $student->withdrawal_reason);
        $this->assertSame(400, $student->current_level);
        $this->assertDatabaseHas('student_level_history', ['student_profile_id' => $student->id, 'outcome' => 'withdrawn']);
        $this->assertTrue($student->user->notifications()->where('data->title', 'Residency ended')->exists());
        $this->assertTrue($this->hod->notifications()->where('data->title', 'Student withdrawn: residency')->exists());
    }

    public function test_a_student_who_graduated_is_not_counted_against_residency(): void
    {
        $student = $this->student(400, 'graduated');

        $this->next();

        $this->assertSame('graduated', $student->fresh()->status);
        $this->assertSame(0, $student->fresh()->sessions_at_400);
    }

    public function test_the_hod_records_a_senate_extension_and_the_student_continues(): void
    {
        $student = $this->student(400, 'active', $this->s1, ['sessions_at_400' => 2]);
        $url = "/admin/students/{$student->id}/residency-extension";

        $this->actingAs($this->hod)->post($url, ['sessions_granted' => 1, 'senate_reference' => 'SEN/2026/114'])->assertSessionHasNoErrors();
        $this->assertSame(4, $student->fresh()->allowedSessionsAt400());

        $this->next('2026/2027');        // 3rd session finished: still allowed, thanks to the extension
        $this->assertSame('active', $student->fresh()->status);
        $this->next('2027/2028');        // 4th finished: now used up
        $this->assertSame('withdrawn', $student->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'residency.extension_granted']);
    }

    public function test_an_extension_after_withdrawal_reinstates_the_student(): void
    {
        $student = $this->student(400, 'withdrawn', $this->s1, ['sessions_at_400' => 3, 'withdrawal_reason' => 'residency_exceeded']);

        $this->actingAs($this->hod)->post("/admin/students/{$student->id}/residency-extension", ['sessions_granted' => 1, 'senate_reference' => 'SEN/2026/114', 'note' => 'Appeal granted'])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame('active', $student->status);
        $this->assertNull($student->withdrawal_reason);
        $this->assertSame(1, $student->sessionsAt400Left());
        $this->assertTrue($student->user->notifications()->where('data->title', 'Residency extended')->exists());
    }

    public function test_an_extension_does_not_reinstate_a_student_withdrawn_for_another_reason(): void
    {
        $student = $this->student(400, 'withdrawn');

        $this->actingAs($this->hod)->post("/admin/students/{$student->id}/residency-extension", ['sessions_granted' => 1, 'senate_reference' => 'X'])
            ->assertSessionHasErrors('sessions_granted');

        $this->assertSame('withdrawn', $student->fresh()->status);
        $this->assertSame(0, ResidencyExtension::count());
    }

    public function test_extensions_are_hod_only_and_validated(): void
    {
        $student = $this->student(400);
        $url = "/admin/students/{$student->id}/residency-extension";
        $ok = ['sessions_granted' => 1, 'senate_reference' => 'SEN/1'];

        $this->actingAs($this->eo)->post($url, $ok)->assertForbidden();
        $this->actingAs($this->hod)->post($url, ['senate_reference' => ''] + $ok)->assertSessionHasErrors('senate_reference');
        $this->actingAs($this->hod)->post($url, ['sessions_granted' => 0] + $ok)->assertSessionHasErrors('sessions_granted');
        $this->actingAs($this->hod)->post($url, ['sessions_granted' => 4] + $ok)->assertSessionHasErrors('sessions_granted');
        $this->actingAs($this->hod)->post("/admin/students/{$this->student(300)->id}/residency-extension", $ok)->assertSessionHasErrors('sessions_granted'); // not at 400 level
        $this->assertSame(0, ResidencyExtension::count());
    }

    public function test_residency_shows_on_the_student_page_and_dashboard(): void
    {
        $student = $this->student(400, 'active', $this->s1, ['sessions_at_400' => 1]);

        $this->actingAs($this->hod)->get("/admin/students/{$student->id}")->assertSee('Residency at 400 level')->assertSee('Finished 1 of 3')->assertSee('Record a Senate-approved extension');
        $this->actingAs($this->eo)->get("/admin/students/{$student->id}")->assertSee('Finished 1 of 3')->assertDontSee('Record a Senate-approved extension');
        $this->actingAs($student->user)->get('/student')->assertSee('Time left at 400 level')->assertSee('2 session(s)');
    }
}
