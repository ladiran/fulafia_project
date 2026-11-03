<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Graduate;
use App\Models\GraduateResult;
use App\Models\GraduationBatch;
use App\Models\GraduationRequirement;
use App\Models\Result;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\GpaService;
use App\Services\GraduationService;
use App\Services\GradingService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraduationTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;
    private User $hod;
    private User $eo;
    private Specialization $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
        $this->area = Specialization::create(['name' => 'Geophysics']);
        GraduationRequirement::create(['entry_mode' => 'regular', 'min_credits_registered' => 9, 'min_credits_passed' => 9]);
        GraduationRequirement::create(['entry_mode' => 'direct_entry', 'min_credits_registered' => 6, 'min_credits_passed' => 6]);
    }

    private function course(string $code, int $level, string $type = 'C', array $extra = []): Course
    {
        return Course::create($extra + ['code' => $code, 'title' => $code, 'units' => 3, 'level' => $level, 'semester' => 1, 'type' => $type]);
    }

    private function student(string $mode = 'regular', int $level = 400, bool $withArea = true): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT, 'identifier' => 'FUL/PHY/'.\Illuminate\Support\Str::random(6)])->id,
            'entry_mode' => $mode, 'entry_level' => $mode === 'direct_entry' ? 200 : 100, 'gender' => 'female',
            'current_level' => $level, 'specialization_id' => $withArea ? $this->area->id : null,
        ]);
    }

    /** Register (approved) and record a published result. */
    private function take(StudentProfile $student, Course $course, ?float $total = 65, ?AcademicSession $session = null, ?string $flag = null): CourseRegistration
    {
        $session ??= $this->session;
        $reg = CourseRegistration::create([
            'student_profile_id' => $student->id, 'course_id' => $course->id,
            'academic_session_id' => $session->id, 'semester' => $course->semester, 'status' => 'approved',
        ]);
        $offering = CourseOffering::firstOrCreate(
            ['course_id' => $course->id, 'academic_session_id' => $session->id],
            ['status' => 'published'],
        );
        if ($total !== null || $flag) {
            $ca = $total === null ? null : min(30, round($total * 0.3, 2));
            $exam = $total === null ? null : round($total - $ca, 2);
            Result::create(['course_registration_id' => $reg->id, 'course_offering_id' => $offering->id, 'ca' => $ca, 'exam' => $exam, 'flag' => $flag]
                + app(GradingService::class)->evaluate($ca, $exam, $flag));
        }

        return $reg;
    }

    /** A student who meets every requirement (9 credits, First Class). */
    private function eligibleStudent(): array
    {
        $core1 = $this->course('PHY 101', 100);
        $core2 = $this->course('PHY 401', 400);
        $areaCourse = $this->course('PHY 411', 400, 'E', ['specialization_id' => $this->area->id]);
        $student = $this->student();
        foreach ([$core1, $core2, $areaCourse] as $c) {
            $this->take($student, $c, 80);
        }

        return [$student, $core1, $core2, $areaCourse];
    }

    private function check(StudentProfile $s): array
    {
        return app(GraduationService::class)->evaluate($s);
    }

    public function test_a_student_meeting_every_requirement_is_eligible(): void
    {
        [$student] = $this->eligibleStudent();
        $result = $this->check($student);

        $this->assertTrue($result['eligible'], json_encode($result['checks']));
        $this->assertSame(9, $result['credits_passed']);
    }

    public function test_a_core_course_never_registered_blocks_graduation(): void
    {
        [$student] = $this->eligibleStudent();
        $this->course('PHY 301', 300); // core, never taken

        $result = $this->check($student);
        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('PHY 301', collect($result['checks'])->firstWhere('label', 'All core courses registered and passed')['detail']);
    }

    public function test_a_failed_core_course_blocks_and_must_be_registered_again(): void
    {
        $failed = $this->course('PHY 101', 100);
        $student = $this->student(level: 200, withArea: false);
        $old = AcademicSession::create(['name' => '2024/2025', 'is_current' => false, 'current_semester' => 1]);
        $this->take($student, $failed, 30, $old);

        $this->assertFalse($this->check($student)['eligible']);

        $service = app(RegistrationService::class);
        $carry = $service->availableCourses($student, $this->session)->firstWhere('id', $failed->id);
        $this->assertNotNull($carry, 'failed course is offered again');
        $this->assertTrue($carry->carry_over);
        $this->assertTrue($carry->mandatory);

        $this->actingAs($student->user)->post('/student/registration', ['courses' => []])->assertSessionHasErrors('courses');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$failed->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, CourseRegistration::where('academic_session_id', $this->session->id)->count());
    }

    public function test_a_failed_elective_must_be_reregistered_or_waived_and_only_the_hod_can_waive(): void
    {
        [$student] = $this->eligibleStudent();
        $elective = $this->course('CSC 101', 100, 'E');
        $this->take($student, $elective, 20);

        $this->assertFalse($this->check($student)['eligible']);

        $url = "/admin/graduation/students/{$student->id}/waive/{$elective->id}";
        $this->actingAs($this->eo)->post($url, ['reason' => 'Not offered'])->assertForbidden();
        $this->actingAs($this->hod)->post($url, ['reason' => 'Not offered this year'])->assertSessionHasNoErrors();

        $result = $this->check($student->fresh());
        $this->assertTrue($result['eligible']);
        $this->assertSame(9, $result['credits_passed'], 'a waived course does not count as passed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'elective.waived']);
    }

    public function test_core_and_area_courses_cannot_be_waived(): void
    {
        $student = $this->student();
        $core = $this->course('PHY 101', 100);
        $areaCourse = $this->course('PHY 411', 400, 'E', ['specialization_id' => $this->area->id]);
        $this->take($student, $core, 20);
        $this->take($student, $areaCourse, 20);

        foreach ([$core, $areaCourse] as $course) {
            $this->actingAs($this->hod)->post("/admin/graduation/students/{$student->id}/waive/{$course->id}", ['reason' => 'x'])->assertStatus(422);
        }
        $this->assertDatabaseCount('course_waivers', 0);
    }

    public function test_credit_minimums_apply_per_entry_mode(): void
    {
        [$student] = $this->eligibleStudent();
        GraduationRequirement::where('entry_mode', 'regular')->update(['min_credits_passed' => 12]);

        $this->assertFalse($this->check($student)['eligible']);
    }

    public function test_direct_entry_skips_100_level_core_except_gst(): void
    {
        $this->course('PHY 101', 100);                       // exempt for Direct Entry
        $gst = $this->course('GST 111', 100, 'C', ['is_gst' => true]);
        $core = $this->course('PHY 401', 400);
        $student = $this->student('direct_entry', 400, false);
        $student->update(['specialization_id' => $this->area->id]);

        $this->take($student, $core, 70);
        $this->assertFalse($this->check($student)['eligible'], 'GST 111 is still required');

        $this->take($student, $gst, 70);
        $this->assertTrue($this->check($student)['eligible'], json_encode($this->check($student)['checks']));
    }

    public function test_a_withheld_result_blocks_graduation(): void
    {
        [$student, , $core2] = $this->eligibleStudent();
        Result::where('course_registration_id', CourseRegistration::where('course_id', $core2->id)->value('id'))
            ->update(['flag' => 'withheld', 'total' => null, 'grade' => null, 'grade_point' => null]);

        $this->assertFalse($this->check($student)['eligible']);
    }

    public function test_the_full_graduation_flow_creates_a_permanent_record(): void
    {
        [$student] = $this->eligibleStudent();
        $ineligible = $this->student();

        // The EO cannot propose someone who is not eligible.
        $this->actingAs($this->eo)->post('/admin/graduation/propose', ['students' => [$ineligible->id]])->assertSessionHasErrors('students');

        $this->actingAs($this->eo)->post('/admin/graduation/propose', ['students' => [$student->id]])->assertSessionHasNoErrors();
        $batch = GraduationBatch::firstOrFail();

        $this->actingAs($this->eo)->post("/admin/graduation/batches/{$batch->id}/approve")->assertForbidden();
        $this->assertSame(0, Graduate::count());

        $this->actingAs($this->hod)->post("/admin/graduation/batches/{$batch->id}/approve")->assertSessionHasNoErrors();

        $graduate = Graduate::firstOrFail();
        $this->assertSame('First Class', $graduate->class_of_degree);
        $this->assertSame(9, $graduate->credits_passed);
        $this->assertSame('Geophysics', $graduate->specialization);
        $this->assertSame(3, GraduateResult::count());
        $this->assertSame('graduated', $student->fresh()->status);
        $this->assertSame(User::ROLE_ALUMNI, $student->user->fresh()->role);
        $this->assertDatabaseHas('student_level_history', ['student_profile_id' => $student->id, 'outcome' => 'graduated']);

        // Permanent: no edits or deletes.
        $this->expectException(\LogicException::class);
        $graduate->update(['cgpa' => 1.0]);
    }

    public function test_graduate_records_cannot_be_deleted(): void
    {
        [$student] = $this->eligibleStudent();
        $batch = GraduationBatch::create(['academic_session_id' => $this->session->id, 'status' => 'proposed']);
        $batch->students()->attach($student->id);
        app(GraduationService::class)->graduate($batch, $this->hod);

        $this->expectException(\LogicException::class);
        Graduate::firstOrFail()->delete();
    }

    public function test_approval_rechecks_eligibility(): void
    {
        [$student, $core1] = $this->eligibleStudent();
        $batch = GraduationBatch::create(['academic_session_id' => $this->session->id, 'status' => 'proposed']);
        $batch->students()->attach($student->id);

        // A result is withdrawn after the batch was proposed.
        CourseOffering::where('course_id', $core1->id)->update(['status' => 'draft']);

        $this->actingAs($this->hod)->post("/admin/graduation/batches/{$batch->id}/approve")->assertSessionHasErrors('batch');
        $this->assertSame(0, Graduate::count());
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_alumni_see_their_statement_and_the_public_can_verify_the_code(): void
    {
        [$student] = $this->eligibleStudent();
        $batch = GraduationBatch::create(['academic_session_id' => $this->session->id, 'status' => 'proposed']);
        $batch->students()->attach($student->id);
        app(GraduationService::class)->graduate($batch, $this->hod);
        $graduate = Graduate::firstOrFail();

        $alumnus = $student->user->fresh();
        $this->actingAs($alumnus)->get('/student/statement')->assertOk()->assertSee('First Class')->assertSee($graduate->verification_code);
        $this->actingAs($alumnus)->get('/student/registration')->assertForbidden();

        auth()->logout();
        $this->get('/verify?code='.strtolower($graduate->verification_code))->assertOk()->assertSee('Verified')->assertSee($graduate->matric);
        $this->get('/verify?code=NOTACODE123')->assertOk()->assertSee('No graduate matches');
    }

    /** @dataProvider classes */
    #[\PHPUnit\Framework\Attributes\DataProvider('classes')]
    public function test_nuc_classes_of_degree(float $cgpa, ?string $class): void
    {
        $this->assertSame($class, app(GraduationService::class)->classOf($cgpa));
    }

    public static function classes(): array
    {
        return [
            [5.0, 'First Class'], [4.5, 'First Class'], [4.49, 'Second Class Upper'], [3.5, 'Second Class Upper'],
            [3.49, 'Second Class Lower'], [2.4, 'Second Class Lower'], [2.39, 'Third Class'], [1.5, 'Third Class'],
            [1.49, 'Pass'], [1.0, 'Pass'], [0.99, null],
        ];
    }

    public function test_graduation_pages_are_closed_to_other_roles(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students', 'manage_courses']]);

        foreach (['/admin/graduation', '/admin/graduates'] as $url) {
            $this->actingAs($lecturer)->get($url)->assertForbidden();
            $this->actingAs($admin)->get($url)->assertForbidden();
            $this->actingAs($this->eo)->get($url)->assertOk();
        }
    }

    public function test_student_dashboard_shows_graduation_progress_from_300_level(): void
    {
        $student = $this->student(level: 400);
        $this->actingAs($student->user)->get('/student')->assertOk()->assertSee('Graduation progress');
        $this->assertNotNull(app(GpaService::class)); // sanity: services resolve
    }
}
