<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Graduate;
use App\Models\GraduateResult;
use App\Models\Result;
use App\Models\StudentNameHistory;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\GradingService;
use App\Services\TranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TranscriptTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;
    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN, 'name' => 'Prof. Head']);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN, 'name' => 'Mr. Exam Officer']);
    }

    private function student(string $name = 'Ada Obi'): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => $name, 'identifier' => 'FUL/PHY/'.Str::random(5)])->id,
            'entry_mode' => 'regular', 'entry_level' => 100, 'gender' => 'female', 'current_level' => 200,
        ]);
    }

    private function take(StudentProfile $student, string $code, float $total, int $units = 3, string $status = 'published', ?string $flag = null): void
    {
        $course = Course::firstOrCreate(['code' => $code], ['title' => "Title of {$code}", 'units' => $units, 'level' => 100, 'semester' => 1, 'type' => 'C']);
        $reg = CourseRegistration::create([
            'student_profile_id' => $student->id, 'course_id' => $course->id,
            'academic_session_id' => $this->session->id, 'semester' => 1, 'status' => 'approved',
        ]);
        $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'academic_session_id' => $this->session->id], ['status' => $status]);
        $ca = $flag ? null : round($total * 0.3, 2);
        $exam = $flag ? null : round($total - $ca, 2);
        Result::create(['course_registration_id' => $reg->id, 'course_offering_id' => $offering->id, 'ca' => $ca, 'exam' => $exam, 'flag' => $flag]
            + app(GradingService::class)->evaluate($ca, $exam, $flag));
    }

    private function graduate(StudentProfile $student): Graduate
    {
        $graduate = Graduate::create([
            'student_profile_id' => $student->id, 'matric' => $student->user->identifier, 'name' => 'Ada Obi (record)', 'gender' => 'female',
            'entry_mode' => 'regular', 'entry_session' => '2021/2022', 'graduation_session' => '2025/2026', 'graduation_year' => 2026,
            'degree' => 'B.Sc. Physics', 'specialization' => 'Geophysics', 'cgpa' => 4.25, 'credits_registered' => 9, 'credits_passed' => 9,
            'class_of_degree' => 'Second Class Upper', 'verification_code' => Str::upper(Str::random(10)), 'approved_at' => now(),
        ]);
        foreach ([
            ['PHY 101', 'General Physics', 3, 'C', 70, 'A', 5, null, false, false],
            ['PHY 102', 'Mechanics', 3, 'C', 60, 'B', 4, null, false, false],
            ['CSC 101', 'Computing', 3, 'E', null, null, null, null, true, false],
            ['MTH 101', 'Mathematics', 3, 'C', null, null, null, null, false, true],
        ] as [$code, $title, $units, $type, $total, $grade, $gp, $flag, $waived, $exempted]) {
            GraduateResult::create([
                'graduate_id' => $graduate->id, 'session' => '2025/2026', 'semester' => 1, 'course_code' => $code, 'course_title' => $title,
                'units' => $units, 'course_type' => $type, 'total' => $total, 'grade' => $grade, 'grade_point' => $gp, 'flag' => $flag,
                'waived' => $waived, 'exempted' => $exempted,
            ]);
        }

        return $graduate;
    }

    private function html(array $data): string
    {
        return view('pdf.transcript', $data + ['generated_by' => 'Tester', 'generated_at' => now()])->render();
    }

    // ---- Access ----

    public function test_only_the_hod_and_eo_can_get_a_departmental_transcript_pdf(): void
    {
        $student = $this->student();
        $this->take($student, 'PHY 101', 70);
        $url = "/admin/transcripts/students/{$student->id}";

        foreach ([$this->hod, $this->eo] as $admin) {
            $response = $this->actingAs($admin)->get($url)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        foreach ([
            User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students', 'manage_results', 'manage_content']]),
            User::factory()->create(['role' => User::ROLE_LECTURER]),
            $student->user,
        ] as $other) {
            $this->actingAs($other)->get($url)->assertForbidden();
        }
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_graduate_transcripts_are_admin_only_too_and_students_have_no_pdf_route(): void
    {
        $student = $this->student();
        $graduate = $this->graduate($student);

        $this->actingAs($this->eo)->get("/admin/transcripts/graduates/{$graduate->id}")->assertOk();
        $this->actingAs($student->user)->get("/admin/transcripts/graduates/{$graduate->id}")->assertForbidden();
        $this->actingAs($student->user)->get('/student/transcript')->assertNotFound();
    }

    public function test_generating_a_transcript_is_logged_and_can_be_downloaded(): void
    {
        $student = $this->student();

        $response = $this->actingAs($this->eo)->get("/admin/transcripts/students/{$student->id}?download=1")->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('departmental-transcript', $response->headers->get('Content-Disposition'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'transcript.generated', 'user_id' => $this->eo->id]);
        $this->assertNull($response->headers->get('Content-Security-Policy'), 'the PDF viewer needs no CSP');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    // ---- Not official ----

    public function test_every_transcript_says_it_is_not_official_and_for_departmental_use_only(): void
    {
        $student = $this->student();
        $service = app(TranscriptService::class);

        foreach ([$service->forStudent($student), $service->forGraduate($this->graduate($student))] as $data) {
            $html = $this->html($data);

            $this->assertStringContainsString('NOT AN OFFICIAL TRANSCRIPT', $html);
            $this->assertStringContainsString('For departmental use only', $html);
            $this->assertStringContainsString('must not be given out as one', $html);
            $this->assertStringNotContainsString('Verification code', $html);
            $this->assertStringNotContainsString('Registrar', $html);
        }
    }

    // ---- Content ----

    public function test_an_interim_transcript_lists_published_results_only_with_gpa(): void
    {
        $student = $this->student();
        $this->take($student, 'PHY 101', 75);                     // A, 3 units
        $this->take($student, 'PHY 102', 52, 2);                  // C, 2 units
        $this->take($student, 'PHY 103', 90, 3, 'approved');      // not published: must not appear
        $this->take($student, 'PHY 104', 0, 3, 'published', 'withheld');

        $data = app(TranscriptService::class)->forStudent($student);
        $html = $this->html($data);

        $this->assertSame('interim', $data['kind']);
        $this->assertStringContainsString('PHY 101', $html);
        $this->assertStringContainsString('Withheld', $html);
        $this->assertStringNotContainsString('PHY 103', $html);
        // (3*5 + 2*3) / 5 = 4.2; the withheld course is left out.
        $this->assertSame(5, $data['totals']['tcu']);
        $this->assertEquals(4.2, $data['totals']['cgpa']);
        $this->assertStringContainsString('interim', $html);
    }

    public function test_a_graduate_transcript_comes_from_the_frozen_record(): void
    {
        $student = $this->student('Ada Obi');
        $graduate = $this->graduate($student);
        // Later changes to live data must not alter it.
        $student->user->update(['name' => 'Totally Different Name']);
        $this->take($student, 'PHY 999', 10);

        $data = app(TranscriptService::class)->forStudent($student->fresh());
        $html = $this->html($data);

        $this->assertSame('graduate', $data['kind']);
        $this->assertSame('Ada Obi (record)', $data['person']['name']);
        $this->assertStringNotContainsString('Totally Different Name', $html);
        $this->assertStringNotContainsString('PHY 999', $html);
        $this->assertStringContainsString('Second Class Upper', $html);
        $this->assertStringContainsString('B.Sc. Physics', $html);
        $this->assertStringContainsString('Geophysics', $html);
        $this->assertEquals(4.25, $data['totals']['cgpa']);
    }

    public function test_waived_and_exempted_courses_are_shown_but_carry_no_grade_points(): void
    {
        $graduate = $this->graduate($this->student());

        $data = app(TranscriptService::class)->forGraduate($graduate);
        $html = $this->html($data);
        $semester = $data['semesters'][0];

        $this->assertStringContainsString('Waived by the HoD', $html);
        $this->assertStringContainsString('Exempted (transfer credit)', $html);
        // Only PHY 101 (A) and PHY 102 (B): TCU 6, TGP 3*5 + 3*4 = 27, GPA 4.5.
        $this->assertSame(6, $semester['tcu']);
        $this->assertSame(27, $semester['tgp']);
        $this->assertEquals(4.5, $semester['gpa']);
    }

    public function test_previous_names_are_shown_for_departmental_reference(): void
    {
        $student = $this->student('Ada Okafor');
        StudentNameHistory::create(['student_profile_id' => $student->id, 'previous_name' => 'Ada Obi', 'new_name' => 'Ada Okafor', 'effective_date' => today()]);

        $html = $this->html(app(TranscriptService::class)->forStudent($student));

        $this->assertStringContainsString('formerly Ada Obi', $html);
    }

    public function test_a_student_with_no_published_results_still_gets_a_valid_pdf(): void
    {
        $student = $this->student();

        $response = $this->actingAs($this->hod)->get("/admin/transcripts/students/{$student->id}")->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('No published results yet', $this->html(app(TranscriptService::class)->forStudent($student)));
    }

    public function test_the_graduate_and_student_pages_link_to_the_pdf_with_the_warning(): void
    {
        $student = $this->student();
        $graduate = $this->graduate($student);

        $this->actingAs($this->hod)->get("/admin/graduates/{$graduate->id}")->assertSee('Departmental transcript (PDF)')->assertSee('not an official transcript');
        $this->actingAs($this->eo)->get("/admin/students/{$student->id}")->assertSee('Departmental transcript (PDF)');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students']]))->get("/admin/students/{$student->id}")->assertDontSee('Departmental transcript (PDF)');
    }
}
