<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\Deferment;
use App\Models\Enquiry;
use App\Models\NameChangeRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\PortalMessage;
use App\Services\DefermentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DefermentNameChangeTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;
    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
    }

    private function student(string $gender = 'female', string $status = 'active', int $level = 200): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT, 'identifier' => 'FUL/PHY/'.Str::random(6)])->id,
            'entry_mode' => 'regular', 'entry_level' => 100, 'gender' => $gender, 'current_level' => $level, 'status' => $status,
        ]);
    }

    /** Make the student "fully registered" in an earlier session. */
    private function registered(StudentProfile $student, ?AcademicSession $session = null, string $status = 'approved'): CourseRegistration
    {
        $session ??= AcademicSession::firstOrCreate(['name' => '2024/2025']);
        $course = Course::create(['code' => 'PHY '.random_int(100, 999), 'title' => 'x', 'units' => 3, 'level' => 100, 'semester' => 1, 'type' => 'C']);

        return CourseRegistration::create([
            'student_profile_id' => $student->id, 'course_id' => $course->id,
            'academic_session_id' => $session->id, 'semester' => 1, 'status' => $status,
        ]);
    }

    private function defer(StudentProfile $student, ?AcademicSession $session = null, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->hod)->post('/admin/deferments', [
            'matric' => $student->user->identifier,
            'academic_session_id' => ($session ?? $this->session)->id,
            'reason' => 'Health',
        ]);
    }

    // ---- Deferment ----

    public function test_only_the_hod_can_start_a_deferment(): void
    {
        $student = $this->student();
        $this->registered($student);

        $this->defer($student, by: $this->eo)->assertForbidden();
        $this->defer($student, by: User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students']]))->assertForbidden();
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_the_student_must_have_been_fully_registered_first(): void
    {
        $student = $this->student();

        $this->defer($student)->assertSessionHasErrors('matric');
        $this->assertSame(0, Deferment::count());

        $this->registered($student, status: 'pending'); // pending is not enough
        $this->defer($student)->assertSessionHasErrors('matric');

        $this->registered($student);
        $this->defer($student)->assertSessionHasNoErrors();
        $this->assertSame('deferred', $student->fresh()->status);
    }

    public function test_a_deferred_student_cannot_register_and_sees_a_notice(): void
    {
        $student = $this->student();
        $this->registered($student);
        $this->defer($student);

        $this->actingAs($student->user->fresh())->post('/student/registration', ['courses' => []])->assertForbidden();
        $this->actingAs($student->user->fresh())->get('/student')->assertOk()->assertSee('Your admission is deferred');
        $this->assertNotEmpty($student->user->notifications);
    }

    public function test_pending_registrations_in_the_deferred_session_are_cancelled(): void
    {
        $student = $this->student();
        $this->registered($student);
        $pending = $this->registered($student, $this->session, 'pending');

        $this->defer($student)->assertSessionHasNoErrors();

        $this->assertSame('rejected', $pending->fresh()->status);
    }

    public function test_a_session_with_approved_registrations_cannot_be_deferred(): void
    {
        $student = $this->student();
        $this->registered($student, $this->session); // approved in the session to defer

        $this->defer($student)->assertSessionHasErrors('matric');
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_an_earlier_session_cannot_be_deferred(): void
    {
        $student = $this->student();
        $this->registered($student);

        $this->defer($student, AcademicSession::where('name', '2024/2025')->first())->assertSessionHasErrors('academic_session_id');
    }

    public function test_at_most_one_session_can_ever_be_deferred(): void
    {
        $student = $this->student();
        $this->registered($student);
        $this->defer($student)->assertSessionHasNoErrors();
        $this->actingAs($this->eo)->post('/admin/deferments/'.Deferment::firstOrFail()->id.'/return')->assertSessionHasNoErrors();
        $this->assertSame('active', $student->fresh()->status);

        $next = AcademicSession::create(['name' => '2026/2027']);
        $this->defer($student->fresh(), $next)->assertSessionHasErrors('matric');
        $this->assertSame(1, Deferment::count());
    }

    public function test_a_suspended_student_cannot_be_deferred(): void
    {
        $student = $this->student(status: 'suspended');
        $this->registered($student);

        $this->defer($student)->assertSessionHasErrors('matric');
    }

    public function test_the_eo_can_return_a_deferred_student_at_the_same_level(): void
    {
        $student = $this->student(level: 300);
        $this->registered($student);
        $this->defer($student);
        $deferment = Deferment::firstOrFail();

        $this->actingAs($this->eo)->post("/admin/deferments/{$deferment->id}/return")->assertSessionHasNoErrors();

        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame(300, $student->fresh()->current_level);
        $this->assertSame('returned', $deferment->fresh()->status);
    }

    public function test_a_deferment_lapses_when_the_session_ends_and_only_the_hod_can_withdraw(): void
    {
        $student = $this->student();
        $this->registered($student);
        $this->defer($student);

        // Next session starts without the student returning.
        $next = AcademicSession::create(['name' => '2026/2027']);
        $next->makeCurrent();
        $this->artisan('deferments:flag-lapsed')->assertSuccessful();

        $deferment = Deferment::firstOrFail();
        $this->assertSame('lapsed', $deferment->status);
        $this->assertSame('deferred', $student->fresh()->status, 'the student stays deferred until the HoD acts');
        $this->assertTrue($this->hod->notifications()->where('data->title', 'Deferment lapsed')->exists());

        $this->actingAs($this->eo)->post("/admin/deferments/{$deferment->id}/withdraw")->assertForbidden();
        $this->actingAs($this->hod)->post("/admin/deferments/{$deferment->id}/withdraw")->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $student->fresh()->status);
    }

    public function test_an_active_deferment_cannot_be_withdrawn(): void
    {
        $student = $this->student();
        $this->registered($student);
        $this->defer($student);

        $this->actingAs($this->hod)->post('/admin/deferments/'.Deferment::firstOrFail()->id.'/withdraw')->assertSessionHasErrors('deferment');
        $this->assertSame('deferred', $student->fresh()->status);
    }

    public function test_deferred_students_do_not_move_up_when_the_next_session_starts(): void
    {
        $student = $this->student(level: 100);
        $this->registered($student);
        $this->defer($student);

        $this->actingAs($this->eo)->post('/admin/sessions/start-next', ['name' => '2026/2027'])->assertSessionHasNoErrors();
        $this->assertSame(100, $student->fresh()->current_level, 'a deferred student is out for the session and keeps their level');
        $this->assertSame('deferred', $student->fresh()->status);
    }

    // ---- Change of name ----

    private function submitName(StudentProfile $student, array $override = [])
    {
        return $this->actingAs($student->user)->post('/student/name-change', $override + [
            'new_name' => 'Ada Okafor-Bello',
            'document_type' => 'certificate',
            'marriage_document' => UploadedFile::fake()->create('certificate.pdf', 200, 'application/pdf'),
            'newspaper_advert' => UploadedFile::fake()->create('advert.jpg', 300, 'image/jpeg'),
        ]);
    }

    public function test_only_female_students_can_request_a_name_change(): void
    {
        Storage::fake('local');
        $male = $this->student('male');

        $this->submitName($male)->assertSessionHasErrors('new_name');
        $this->assertSame(0, NameChangeRequest::count());
        $this->actingAs($male->user)->get('/student/name-change')->assertSee('Only female students');
    }

    public function test_a_request_needs_both_documents_and_a_real_change(): void
    {
        Storage::fake('local');
        $student = $this->student();

        $this->submitName($student, ['marriage_document' => null])->assertSessionHasErrors('marriage_document');
        $this->submitName($student, ['newspaper_advert' => null])->assertSessionHasErrors('newspaper_advert');
        $this->submitName($student, ['new_name' => $student->user->name])->assertSessionHasErrors('new_name');
        $this->submitName($student, ['marriage_document' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('marriage_document');
        $this->assertSame(0, NameChangeRequest::count());
    }

    public function test_submitting_stores_private_files_notifies_the_eo_and_hod_and_allows_only_one_open_request(): void
    {
        Storage::fake('local');
        $student = $this->student();

        $this->submitName($student)->assertSessionHasNoErrors();
        $request = NameChangeRequest::firstOrFail();

        $this->assertSame('submitted', $request->status);
        Storage::disk('local')->assertExists($request->marriage_document_path);
        Storage::disk('local')->assertExists($request->newspaper_path);
        $this->assertSame(1, $this->hod->notifications()->count());
        $this->assertSame(1, $this->eo->notifications()->count());

        $this->submitName($student)->assertSessionHasErrors('new_name');
        $this->assertSame(1, NameChangeRequest::count());
    }

    public function test_eo_verifies_and_only_the_hod_approves_and_the_name_changes_with_history(): void
    {
        Storage::fake('local');
        $student = $this->student();
        $matric = $student->user->identifier;
        $oldName = $student->user->name;
        $this->submitName($student);
        $request = NameChangeRequest::firstOrFail();

        // The HoD cannot approve before the documents are verified.
        $this->actingAs($this->hod)->post("/admin/name-changes/{$request->id}/approve")->assertSessionHasErrors('note');

        $this->actingAs($this->eo)->post("/admin/name-changes/{$request->id}/verify", ['decision' => 'ok'])->assertSessionHasNoErrors();
        $this->assertSame('verified', $request->fresh()->status);

        $this->actingAs($this->eo)->post("/admin/name-changes/{$request->id}/approve")->assertForbidden();
        $this->assertSame($oldName, $student->user->fresh()->name);

        $this->actingAs($this->hod)->post("/admin/name-changes/{$request->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame('Ada Okafor-Bello', $student->user->fresh()->name);
        $this->assertSame($matric, $student->user->fresh()->identifier, 'the matric number never changes');
        $this->assertDatabaseHas('student_name_history', ['student_profile_id' => $student->id, 'previous_name' => $oldName, 'new_name' => 'Ada Okafor-Bello']);
        $this->assertTrue($student->user->notifications()->where('data->title', 'Name change approved')->exists());
    }

    public function test_rejection_needs_a_reason_and_leaves_the_name_alone(): void
    {
        Storage::fake('local');
        $student = $this->student();
        $oldName = $student->user->name;
        $this->submitName($student);
        $request = NameChangeRequest::firstOrFail();

        $this->actingAs($this->eo)->post("/admin/name-changes/{$request->id}/verify", ['decision' => 'reject'])->assertSessionHasErrors('note');
        $this->actingAs($this->eo)->post("/admin/name-changes/{$request->id}/verify", ['decision' => 'reject', 'note' => 'Advert unreadable'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame($oldName, $student->user->fresh()->name);

        // After a rejection the student may apply again.
        $this->submitName($student)->assertSessionHasNoErrors();
        $this->assertSame(2, NameChangeRequest::count());
    }

    public function test_documents_are_only_downloadable_by_the_eo_and_hod(): void
    {
        Storage::fake('local');
        $student = $this->student();
        $this->submitName($student);
        $request = NameChangeRequest::firstOrFail();
        $url = "/admin/name-changes/{$request->id}/document/marriage";

        $this->actingAs($student->user)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LECTURER]))->get($url)->assertForbidden();
        $this->actingAs($this->eo)->get($url)->assertOk();
        $this->actingAs($this->hod)->get("/admin/name-changes/{$request->id}/document/newspaper")->assertOk();
        $this->actingAs($this->eo)->get("/admin/name-changes/{$request->id}/document/secret")->assertNotFound();
    }

    public function test_graduated_students_cannot_request_a_name_change_here(): void
    {
        $student = $this->student(status: 'graduated');

        $this->assertNotNull(app(\App\Services\NameChangeService::class)->blockedReason($student));
    }

    // ---- Enquiries ----

    public function test_students_send_enquiries_and_the_department_replies(): void
    {
        $student = $this->student();

        $this->actingAs($student->user)->post('/student/enquiries', ['category' => 'deferment', 'subject' => 'Deferral', 'message' => 'I need to defer.'])
            ->assertSessionHasNoErrors();
        $enquiry = Enquiry::firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_LECTURER]))->post("/admin/enquiries/{$enquiry->id}/respond", ['response' => 'x'])->assertForbidden();
        $this->actingAs($this->eo)->post("/admin/enquiries/{$enquiry->id}/respond", ['response' => 'See the HoD.'])->assertSessionHasNoErrors();

        $this->assertSame('closed', $enquiry->fresh()->status);
        $this->assertTrue($student->user->notifications()->where('data->title', 'Reply to your enquiry')->exists());
        $this->actingAs($student->user)->get('/student/enquiries')->assertSee('See the HoD.');
    }

    public function test_deferment_service_is_the_single_gate_for_eligibility(): void
    {
        $student = $this->student();
        $service = app(DefermentService::class);

        $this->assertStringContainsString('fully registered', $service->blockedReason($student));
        $this->registered($student);
        $this->assertNull($service->blockedReason($student));
        $this->assertInstanceOf(PortalMessage::class, new PortalMessage('a', 'b'));
    }
}
