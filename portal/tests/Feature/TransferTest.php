<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseExemption;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Graduate;
use App\Models\GraduationBatch;
use App\Models\GraduationRequirement;
use App\Models\Result;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\TransferEvaluation;
use App\Models\User;
use App\Services\GradingService;
use App\Services\GraduationService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;
    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
    }

    private function course(string $code, int $level, string $type = 'C', array $extra = []): Course
    {
        return Course::create($extra + ['code' => $code, 'title' => $code, 'units' => 3, 'level' => $level, 'semester' => 1, 'type' => $type]);
    }

    private function transfer(int $entry = 200, int $level = 200, string $mode = 'transfer'): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT, 'identifier' => 'FUL/TR/'.Str::random(6)])->id,
            'entry_mode' => $mode, 'entry_level' => $entry, 'gender' => 'male', 'current_level' => $level,
        ]);
    }

    private function evaluation(StudentProfile $s, string $status = 'approved', array $exempt = [], bool $credit = false): TransferEvaluation
    {
        $e = TransferEvaluation::create(['student_profile_id' => $s->id, 'previous_institution' => 'Other Univ', 'status' => $status]);
        foreach ($exempt as $course) {
            CourseExemption::create(['transfer_evaluation_id' => $e->id, 'student_profile_id' => $s->id, 'course_id' => $course->id, 'counts_as_credit' => $credit]);
        }

        return $e;
    }

    private function take(StudentProfile $student, Course $course, float $total = 70): void
    {
        $reg = CourseRegistration::create([
            'student_profile_id' => $student->id, 'course_id' => $course->id,
            'academic_session_id' => $this->session->id, 'semester' => 1, 'status' => 'approved',
        ]);
        $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'academic_session_id' => $this->session->id], ['status' => 'published']);
        $ca = round($total * 0.3, 2);
        $exam = round($total - $ca, 2);
        Result::create(['course_registration_id' => $reg->id, 'course_offering_id' => $offering->id, 'ca' => $ca, 'exam' => $exam]
            + app(GradingService::class)->evaluate($ca, $exam, null));
    }

    private function doc(): UploadedFile
    {
        return UploadedFile::fake()->create('transcript.pdf', 200, 'application/pdf');
    }

    // ---- Access ----

    public function test_only_the_eo_and_hod_reach_the_evaluation(): void
    {
        $student = $this->transfer();
        $url = "/admin/transfers/{$student->id}";

        $this->actingAs($this->eo)->get($url)->assertOk();
        $this->actingAs($this->hod)->get($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students']]))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LECTURER]))->get($url)->assertForbidden();
        $this->actingAs($student->user)->get($url)->assertForbidden();
        $this->actingAs($this->eo)->get('/admin/transfers')->assertOk()->assertSee($student->user->identifier);
    }

    public function test_only_transfer_students_have_an_evaluation(): void
    {
        $regular = $this->transfer(100, 100, 'regular');

        $this->actingAs($this->eo)->get("/admin/transfers/{$regular->id}")->assertNotFound();
    }

    // ---- Workflow ----

    public function test_the_eo_builds_the_evaluation_and_it_locks_on_submission(): void
    {
        $a = $this->course('PHY 101', 100);
        $b = $this->course('PHY 102', 100);
        $student = $this->transfer();
        $base = "/admin/transfers/{$student->id}";

        // Submission needs the institution and a document.
        $this->actingAs($this->eo)->post("{$base}/submit")->assertSessionHasErrors('evaluation');
        $this->actingAs($this->eo)->put($base, ['previous_institution' => 'University of Jos', 'previous_programme' => 'Physics'])->assertSessionHasNoErrors();
        $this->actingAs($this->eo)->post("{$base}/submit")->assertSessionHasErrors('evaluation');
        $this->actingAs($this->eo)->post("{$base}/documents", ['document' => $this->doc()])->assertSessionHasNoErrors();

        $this->actingAs($this->eo)->post("{$base}/exemptions", ['course_id' => $a->id, 'previous_code' => 'PHY 111', 'previous_grade' => 'B', 'counts_as_credit' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->eo)->post("{$base}/exemptions", ['course_id' => $a->id])->assertSessionHasErrors('course_id');
        $this->assertSame(1, CourseExemption::count());

        $this->actingAs($this->eo)->post("{$base}/submit")->assertSessionHasNoErrors();
        $evaluation = TransferEvaluation::firstOrFail();
        $this->assertSame('submitted', $evaluation->status);
        $this->assertTrue($this->hod->notifications()->where('data->title', 'Transfer evaluation to approve')->exists());

        // Locked: no edits, uploads or exemption changes.
        $this->actingAs($this->eo)->put($base, ['previous_institution' => 'Changed'])->assertSessionHasErrors('evaluation');
        $this->actingAs($this->eo)->post("{$base}/exemptions", ['course_id' => $b->id])->assertSessionHasErrors('evaluation');
        $this->actingAs($this->eo)->post("{$base}/documents", ['document' => $this->doc()])->assertSessionHasErrors('evaluation');
        $this->actingAs($this->eo)->delete("{$base}/exemptions/".CourseExemption::first()->id)->assertSessionHasErrors('evaluation');
        $this->assertSame('University of Jos', $evaluation->fresh()->previous_institution);
    }

    public function test_only_the_hod_approves_and_approval_is_final(): void
    {
        $student = $this->transfer();
        $this->evaluation($student, 'submitted');
        $base = "/admin/transfers/{$student->id}";

        $this->actingAs($this->eo)->post("{$base}/approve")->assertForbidden();
        $this->actingAs($this->eo)->post("{$base}/return", ['note' => 'x'])->assertForbidden();

        $this->actingAs($this->hod)->post("{$base}/approve")->assertSessionHasNoErrors();
        $evaluation = TransferEvaluation::firstOrFail();
        $this->assertSame('approved', $evaluation->status);
        $this->assertSame($this->hod->id, $evaluation->approved_by);
        $this->assertTrue($student->user->notifications()->where('data->title', 'Transfer credits approved')->exists());

        // Nothing can change it any more, not even a return.
        $this->actingAs($this->hod)->post("{$base}/return", ['note' => 'oops'])->assertSessionHasErrors('evaluation');
        $this->actingAs($this->eo)->put($base, ['previous_institution' => 'Changed'])->assertSessionHasErrors('evaluation');
        $this->actingAs($this->hod)->post("{$base}/approve")->assertSessionHasErrors('evaluation');
    }

    public function test_the_hod_can_return_a_submitted_evaluation_with_a_note(): void
    {
        $student = $this->transfer();
        $this->evaluation($student, 'submitted');

        $this->actingAs($this->hod)->post("/admin/transfers/{$student->id}/return", ['note' => 'Check the 100L mapping'])->assertSessionHasNoErrors();

        $evaluation = TransferEvaluation::firstOrFail();
        $this->assertSame('draft', $evaluation->status);
        $this->assertSame('Check the 100L mapping', $evaluation->note);
        $this->actingAs($this->eo)->put("/admin/transfers/{$student->id}", ['previous_institution' => 'Fixed'])->assertSessionHasNoErrors();
    }

    public function test_a_course_the_student_already_registered_cannot_be_exempted(): void
    {
        $course = $this->course('PHY 101', 100);
        $student = $this->transfer();
        CourseRegistration::create(['student_profile_id' => $student->id, 'course_id' => $course->id, 'academic_session_id' => $this->session->id, 'semester' => 1, 'status' => 'pending']);

        $this->actingAs($this->eo)->post("/admin/transfers/{$student->id}/exemptions", ['course_id' => $course->id])->assertSessionHasErrors('course_id');
    }

    // ---- Documents ----

    public function test_transfer_documents_are_private(): void
    {
        $student = $this->transfer();
        $other = $this->transfer();
        $this->actingAs($this->eo)->post("/admin/transfers/{$student->id}/documents", ['document' => $this->doc()])->assertSessionHasNoErrors();
        $document = TransferEvaluation::firstOrFail()->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->path);

        $url = "/admin/transfers/{$student->id}/documents/{$document->id}";
        $this->actingAs($this->eo)->get($url)->assertOk();
        $this->actingAs($this->hod)->get($url)->assertOk();
        $this->actingAs($student->user)->get($url)->assertForbidden();
        $this->actingAs($this->eo)->get("/admin/transfers/{$other->id}/documents/{$document->id}")->assertNotFound();

        $this->actingAs($this->eo)->post("/admin/transfers/{$student->id}/documents", ['document' => UploadedFile::fake()->create('run.exe', 5)])->assertSessionHasErrors('document');
    }

    // ---- Registration ----

    public function test_a_transfer_student_cannot_register_until_the_evaluation_is_approved(): void
    {
        $this->course('PHY 201', 200);
        $student = $this->transfer();

        $this->actingAs($student->user)->post('/student/registration', ['courses' => []])->assertForbidden();
        $this->actingAs($student->user)->get('/student/registration')->assertSee('transfer credit evaluation has not been approved');

        $this->evaluation($student->fresh(), 'submitted');
        $this->actingAs($student->user->fresh())->post('/student/registration', ['courses' => []])->assertForbidden();

        TransferEvaluation::query()->update(['status' => 'approved']);
        $this->actingAs($student->user->fresh())->get('/student/registration')->assertOk()->assertSee('PHY 201');
    }

    public function test_exempted_courses_are_not_offered_and_unexempted_earlier_core_courses_are_required(): void
    {
        $exempted = $this->course('PHY 101', 100);
        $owed = $this->course('PHY 102', 100);
        $current = $this->course('PHY 201', 200);
        $student = $this->transfer();
        $this->evaluation($student, 'approved', [$exempted]);

        $available = app(RegistrationService::class)->availableCourses($student->fresh(), $this->session);

        $this->assertFalse($available->contains('id', $exempted->id));
        $this->assertTrue($available->firstWhere('id', $owed->id)->mandatory);
        $this->assertTrue($available->firstWhere('id', $owed->id)->outstanding);
        $this->assertTrue($available->firstWhere('id', $current->id)->mandatory);

        // The earlier-level course must be in the submission.
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$current->id]])->assertSessionHasErrors('courses');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$current->id, $owed->id]])->assertSessionHasNoErrors();
    }

    public function test_earlier_core_courses_already_taken_are_not_demanded_again(): void
    {
        $taken = $this->course('PHY 102', 100);
        $student = $this->transfer();
        $this->evaluation($student, 'approved');
        $this->take($student, $taken);

        $available = app(RegistrationService::class)->availableCourses($student->fresh(), $this->session);

        $this->assertFalse($available->contains('id', $taken->id));
    }

    // ---- Graduation ----

    /** A transfer student ready to graduate: exempt 100L core, passed 200L and 400L core and the area elective. */
    private function graduable(bool $creditCounts): array
    {
        GraduationRequirement::create(['entry_mode' => 'transfer', 'min_credits_registered' => 12, 'min_credits_passed' => 12]);
        $area = Specialization::create(['name' => 'Geophysics']);
        $c100 = $this->course('PHY 101', 100);
        $c200 = $this->course('PHY 201', 200);
        $c400 = $this->course('PHY 401', 400);
        $elective = $this->course('PHY 411', 400, 'E', ['specialization_id' => $area->id]);
        $student = $this->transfer(200, 400);
        $student->update(['specialization_id' => $area->id]);
        $this->evaluation($student, 'approved', [$c100], $creditCounts);
        foreach ([$c200, $c400, $elective] as $c) {
            $this->take($student, $c);
        }

        return [$student->fresh(), $c100];
    }

    public function test_exempted_courses_satisfy_graduation_and_credits_count_only_when_the_hod_allowed_it(): void
    {
        [$student] = $this->graduable(creditCounts: true);
        $with = app(GraduationService::class)->evaluate($student);
        $this->assertTrue($with['eligible'], json_encode($with['checks']));
        $this->assertSame(12, $with['credits_passed']); // 9 passed + 3 exempted credit

        CourseExemption::query()->update(['counts_as_credit' => false]);
        $without = app(GraduationService::class)->evaluate($student->fresh());
        $this->assertFalse($without['eligible']);
        $this->assertSame(9, $without['credits_passed']);
    }

    public function test_an_unexempted_100_level_core_course_still_blocks_a_transfer_student(): void
    {
        [$student] = $this->graduable(creditCounts: true);
        $this->course('PHY 102', 100); // not exempted, never taken

        $result = app(GraduationService::class)->evaluate($student->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('PHY 102', collect($result['checks'])->firstWhere('label', 'All core courses registered and passed')['detail']);
    }

    public function test_graduation_needs_an_approved_evaluation(): void
    {
        [$student] = $this->graduable(creditCounts: true);
        TransferEvaluation::query()->update(['status' => 'submitted']);

        $result = app(GraduationService::class)->evaluate($student->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertFalse(collect($result['checks'])->firstWhere('label', 'Transfer credit evaluation approved')['ok']);
    }

    public function test_exempted_courses_are_kept_on_the_permanent_record(): void
    {
        [$student] = $this->graduable(creditCounts: true);
        $batch = GraduationBatch::create(['academic_session_id' => $this->session->id, 'status' => 'proposed']);
        $batch->students()->attach($student->id);

        app(GraduationService::class)->graduate($batch, $this->hod);

        $graduate = Graduate::firstOrFail();
        $exempted = $graduate->results()->where('exempted', true)->get();
        $this->assertSame(['PHY 101'], $exempted->pluck('course_code')->all());
        $this->assertSame(12, $graduate->credits_passed);

        $this->actingAs($student->user->fresh())->get('/student/statement')->assertOk()->assertSee('Exempted (transfer credit)');
    }

    public function test_regular_and_direct_entry_students_have_no_evaluation_requirement(): void
    {
        GraduationRequirement::create(['entry_mode' => 'regular', 'min_credits_registered' => 3, 'min_credits_passed' => 3]);
        $course = $this->course('PHY 401', 400);
        $student = $this->transfer(100, 400, 'regular');
        $this->take($student, $course);

        $labels = collect(app(GraduationService::class)->evaluate($student)['checks'])->pluck('label');

        $this->assertFalse($labels->contains('Transfer credit evaluation approved'));
    }

    public function test_the_student_dashboard_shows_the_evaluation_status(): void
    {
        $exempted = $this->course('PHY 101', 100);
        $student = $this->transfer();

        $this->actingAs($student->user)->get('/student')->assertSee('waiting for the department');

        $this->evaluation($student, 'approved', [$exempted]);
        $this->actingAs($student->user->fresh())->get('/student')->assertSee('approved')->assertSee('PHY 101');
    }
}
