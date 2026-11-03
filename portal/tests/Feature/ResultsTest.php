<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Result;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\ResultsPublished;
use App\Services\GpaService;
use App\Services\GradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ResultsTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;
    private User $hod;
    private User $eo;
    private User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
        $this->hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $this->eo = $this->user(User::ROLE_SECONDARY_ADMIN);
        $this->lecturer = $this->user(User::ROLE_LECTURER);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(['role' => $role] + $extra);
    }

    private function course(string $code, int $units = 3): Course
    {
        return Course::create(['code' => $code, 'title' => $code, 'units' => $units, 'level' => 100, 'semester' => 1, 'type' => 'C']);
    }

    private function student(): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => $this->user(User::ROLE_STUDENT)->id, 'entry_mode' => 'regular', 'entry_level' => 100,
            'gender' => 'male', 'current_level' => 100,
        ]);
    }

    private function register(StudentProfile $student, Course $course, string $status = 'approved'): CourseRegistration
    {
        return CourseRegistration::create([
            'student_profile_id' => $student->id, 'course_id' => $course->id,
            'academic_session_id' => $this->session->id, 'semester' => 1, 'status' => $status,
        ]);
    }

    private function offering(Course $course, ?User $lecturer = null, string $status = 'draft'): CourseOffering
    {
        return CourseOffering::create([
            'course_id' => $course->id, 'academic_session_id' => $this->session->id,
            'lecturer_id' => ($lecturer ?? $this->lecturer)->id, 'status' => $status,
        ]);
    }

    private function score(CourseOffering $offering, CourseRegistration $reg, ?float $ca, ?float $exam, ?string $flag = null): Result
    {
        $eval = app(GradingService::class)->evaluate($ca, $exam, $flag);

        return Result::create([
            'course_registration_id' => $reg->id, 'course_offering_id' => $offering->id,
            'ca' => $ca, 'exam' => $exam, 'flag' => $flag,
        ] + $eval);
    }

    /** @dataProvider boundaries */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundaries')]
    public function test_grade_boundaries(float $total, string $grade, int $point): void
    {
        $this->assertSame([$grade, $point], app(GradingService::class)->grade($total));
    }

    public static function boundaries(): array
    {
        return [
            [100, 'A', 5], [70, 'A', 5], [69.99, 'B', 4], [60, 'B', 4], [59.99, 'C', 3], [50, 'C', 3],
            [49.99, 'D', 2], [45, 'D', 2], [44.99, 'E', 1], [40, 'E', 1], [39.99, 'F', 0], [0, 'F', 0],
        ];
    }

    public function test_assigned_lecturer_saves_scores_and_the_grade_is_computed(): void
    {
        $course = $this->course('PHY 101');
        $offering = $this->offering($course);
        $reg = $this->register($this->student(), $course);

        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/scores", ['scores' => [$reg->id => ['ca' => '25', 'exam' => '48.5']]])
            ->assertSessionHasNoErrors();

        $result = Result::firstOrFail();
        $this->assertEquals(73.5, $result->total);
        $this->assertSame('A', $result->grade);
        $this->assertSame(5, $result->grade_point);
    }

    public function test_scores_outside_the_allowed_range_are_rejected(): void
    {
        $course = $this->course('PHY 101');
        $offering = $this->offering($course);
        $reg = $this->register($this->student(), $course);

        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/scores", ['scores' => [$reg->id => ['ca' => '31', 'exam' => '10']]])
            ->assertSessionHasErrors('scores');
        $this->assertSame(0, Result::count());
    }

    public function test_only_approved_registrations_can_be_scored(): void
    {
        $course = $this->course('PHY 101');
        $offering = $this->offering($course);
        $pending = $this->register($this->student(), $course, 'pending');

        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/scores", ['scores' => [$pending->id => ['ca' => '20', 'exam' => '40']]]);
        $this->assertSame(0, Result::count());
    }

    public function test_a_lecturer_cannot_open_another_lecturers_sheet(): void
    {
        $offering = $this->offering($this->course('PHY 101'), $this->user(User::ROLE_LECTURER));

        $this->actingAs($this->lecturer)->get("/results/{$offering->id}")->assertForbidden();
        $this->actingAs($this->eo)->get("/results/{$offering->id}")->assertOk();
    }

    public function test_submission_needs_every_student_scored_and_then_locks_the_sheet(): void
    {
        $course = $this->course('PHY 101');
        $offering = $this->offering($course);
        $a = $this->register($this->student(), $course);
        $b = $this->register($this->student(), $course);

        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/scores", ['scores' => [$a->id => ['ca' => '20', 'exam' => '40']]]);
        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/submit")->assertSessionHasErrors('scores');
        $this->assertSame('draft', $offering->fresh()->status);

        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/scores", ['scores' => [
            $a->id => ['ca' => '20', 'exam' => '40'], $b->id => ['flag' => 'absent'],
        ]]);
        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/submit")->assertSessionHasNoErrors();
        $this->assertSame('submitted', $offering->fresh()->status);

        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/scores", ['scores' => [$a->id => ['ca' => '30', 'exam' => '70']]])
            ->assertSessionHasErrors('scores');
        $this->assertEquals(60.0, Result::where('course_registration_id', $a->id)->value('total'));
    }

    public function test_workflow_roles_vet_then_hod_approves_then_publish(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $course = $this->course('PHY 101');
        $offering = $this->offering($course, status: 'submitted');
        $student = $this->student();
        $reg = $this->register($student, $course);
        $this->score($offering, $reg, 20, 40);

        // Lecturers cannot vet, approve or publish.
        foreach (['vet', 'approve', 'publish'] as $step) {
            $this->actingAs($this->lecturer)->post("/results/{$offering->id}/{$step}")->assertForbidden();
        }

        $this->actingAs($this->eo)->post("/results/{$offering->id}/vet")->assertSessionHasNoErrors();
        $this->assertSame('vetted', $offering->fresh()->status);

        // The EO cannot give final approval, and cannot publish an unapproved sheet.
        $this->actingAs($this->eo)->post("/results/{$offering->id}/approve")->assertForbidden();
        $this->actingAs($this->eo)->post("/results/{$offering->id}/publish")->assertSessionHasErrors('scores');

        $this->actingAs($this->hod)->post("/results/{$offering->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->eo)->post("/results/{$offering->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame('published', $offering->fresh()->status);

        \Illuminate\Support\Facades\Notification::assertSentTo($student->user, ResultsPublished::class);
    }

    public function test_returning_a_sheet_unlocks_it_with_a_note(): void
    {
        $offering = $this->offering($this->course('PHY 101'), status: 'submitted');

        $this->actingAs($this->eo)->post("/results/{$offering->id}/return", ['note' => 'Check row 3'])->assertSessionHasNoErrors();

        $this->assertSame('draft', $offering->fresh()->status);
        $this->assertSame('Check row 3', $offering->fresh()->return_note);
    }

    public function test_students_only_see_published_results(): void
    {
        $course = $this->course('PHY 101');
        $offering = $this->offering($course, status: 'approved');
        $student = $this->student();
        $this->score($offering, $this->register($student, $course), 28, 60);

        $this->actingAs($student->user)->get('/student/results')->assertOk()->assertSee('No published results yet.');

        $offering->update(['status' => 'published']);
        $this->actingAs($student->user)->get('/student/results')->assertOk()->assertSee('PHY 101')->assertSee('GPA 5.00');
    }

    public function test_gpa_and_cgpa_use_credit_units_and_credits_passed_count_each_course_once(): void
    {
        $student = $this->student();
        $a = $this->course('PHY 101', 3); // 75 -> A (5)
        $b = $this->course('PHY 102', 2); // 52 -> C (3)
        $c = $this->course('PHY 103', 3); // 30 -> F (0)
        foreach ([[$a, 25, 50], [$b, 12, 40], [$c, 10, 20]] as [$course, $ca, $exam]) {
            $this->score($this->offering($course, status: 'published'), $this->register($student, $course), $ca, $exam);
        }

        $summary = app(GpaService::class)->summary($student);

        // (3*5 + 2*3 + 3*0) / 8 = 2.625
        $this->assertSame(8, $summary['tcu']);
        $this->assertSame(21, $summary['tgp']);
        $this->assertEquals(2.63, $summary['cgpa']);
        $this->assertSame(5, $summary['credits_passed']);
    }

    public function test_absent_counts_as_f_but_incomplete_and_withheld_are_not_graded(): void
    {
        $student = $this->student();
        $abs = $this->course('PHY 101', 3);
        $inc = $this->course('PHY 102', 3);
        $wh = $this->course('PHY 103', 3);
        $ok = $this->course('PHY 104', 3);
        $this->score($this->offering($abs, status: 'published'), $this->register($student, $abs), null, null, 'absent');
        $this->score($this->offering($inc, status: 'published'), $this->register($student, $inc), 10, null, 'incomplete');
        $this->score($this->offering($wh, status: 'published'), $this->register($student, $wh), 20, 60, 'withheld');
        $this->score($this->offering($ok, status: 'published'), $this->register($student, $ok), 25, 50);

        $summary = app(GpaService::class)->summary($student);

        $this->assertSame(6, $summary['tcu']); // absent (F) + the good course
        $this->assertEquals(2.5, $summary['cgpa']);
    }

    public function test_csv_upload_applies_scores_and_reports_unknown_matric_numbers(): void
    {
        $course = $this->course('PHY 101');
        $offering = $this->offering($course);
        $student = $this->student();
        $student->user->update(['identifier' => 'FUL/PHY/25/001']);
        $this->register($student, $course);

        $csv = UploadedFile::fake()->createWithContent('scores.csv', "matric,ca,exam\nFUL/PHY/25/001,22,41\nNOPE/1,10,10\n");
        $this->actingAs($this->lecturer)->post("/results/{$offering->id}/upload", ['file' => $csv])
            ->assertSessionHasNoErrors()->assertSessionHas('problems');

        $this->assertEquals(63.0, Result::firstOrFail()->total);
        $this->assertSame('B', Result::firstOrFail()->grade);
    }

    public function test_only_the_hod_can_reopen_and_students_stop_seeing_the_results(): void
    {
        $course = $this->course('PHY 101');
        $offering = $this->offering($course, status: 'published');
        $student = $this->student();
        $this->score($offering, $this->register($student, $course), 28, 60);

        $this->actingAs($this->eo)->post("/results/{$offering->id}/reopen", ['reason' => 'Typo'])->assertForbidden();
        $this->actingAs($this->hod)->post("/results/{$offering->id}/reopen", ['reason' => 'Typo in exam score'])->assertSessionHasNoErrors();

        $this->assertSame('draft', $offering->fresh()->status);
        $this->assertSame(0, count(app(GpaService::class)->summary($student)['semesters']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'results.reopened']);
    }

    public function test_results_pages_are_closed_to_students(): void
    {
        $student = $this->student();
        $offering = $this->offering($this->course('PHY 101'));

        $this->actingAs($student->user)->get('/results')->assertForbidden();
        $this->actingAs($student->user)->get("/results/{$offering->id}")->assertForbidden();
    }
}
