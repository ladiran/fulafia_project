<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseExemption;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Graduate;
use App\Models\Result;
use App\Models\StudentProfile;
use App\Models\TransferEvaluation;
use App\Models\User;
use App\Services\BroadsheetService;
use App\Services\CourseSheetService;
use App\Services\GradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BroadsheetTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $s1;
    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s1 = AcademicSession::create(['name' => '2024/2025', 'is_current' => false, 'current_semester' => 1]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN, 'name' => 'Prof. Head']);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
    }

    private function course(string $code, int $units = 3, int $level = 100, int $semester = 1, string $type = 'C'): Course
    {
        return Course::firstOrCreate(['code' => $code], ['title' => "Title {$code}", 'units' => $units, 'level' => $level, 'semester' => $semester, 'type' => $type]);
    }

    private function student(string $matric, int $level = 100, string $mode = 'regular'): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT, 'identifier' => $matric])->id,
            'entry_mode' => $mode, 'entry_level' => $mode === 'direct_entry' ? 200 : 100, 'gender' => 'male', 'current_level' => $level,
        ]);
    }

    /** Register (approved) and score a student. $score null = registered, no result row. */
    private function take(StudentProfile $s, Course $c, ?float $score, ?AcademicSession $session = null, string $status = 'published', ?string $flag = null, ?int $level = 100, ?float $ca2 = null): CourseRegistration
    {
        $session ??= $this->s1;
        $reg = CourseRegistration::create([
            'student_profile_id' => $s->id, 'course_id' => $c->id, 'academic_session_id' => $session->id,
            'semester' => $c->semester, 'level_at_registration' => $level, 'status' => 'approved',
        ]);
        $offering = CourseOffering::firstOrCreate(['course_id' => $c->id, 'academic_session_id' => $session->id], ['status' => $status]);
        if ($score !== null || $flag) {
            $ca = $flag ? null : round(min(30, $score * 0.3), 2);
            $exam = $flag ? null : round($score - $ca, 2);
            Result::create(['course_registration_id' => $reg->id, 'course_offering_id' => $offering->id, 'ca' => $ca, 'ca2' => $ca2, 'exam' => $exam, 'flag' => $flag]
                + app(GradingService::class)->evaluate($ca, $exam, $flag, $ca2));
        }

        return $reg;
    }

    private function sheet(int $level = 100, int $semester = 1, ?AcademicSession $session = null): array
    {
        return app(BroadsheetService::class)->build($session ?? $this->s1, $semester, $level);
    }

    private function row(array $sheet, string $matric): array
    {
        return collect($sheet['rows'])->firstWhere('matric', $matric);
    }

    // ---- CA 1 + CA 2 ----

    public function test_the_total_is_ca1_plus_ca2_plus_exam_and_ca2_is_optional(): void
    {
        $grading = app(GradingService::class);

        $this->assertSame(['total' => 49.0, 'grade' => 'D', 'grade_point' => 2], $grading->evaluate(5, 41, null, 3));
        $this->assertEquals(44.0, $grading->evaluate(10, 34, null)['total']);
        $this->assertNull($grading->evaluate(null, 40, null, 5)['total'], 'CA 1 and the exam are still needed');
    }

    public function test_lecturers_enter_ca1_ca2_and_exam_and_the_two_cas_share_the_ca_limit(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $c = $this->course('PHY 213');
        $s = $this->student('2021700001');
        $reg = $this->take($s, $c, null);
        $offering = CourseOffering::firstOrFail();
        $offering->update(['lecturer_id' => $lecturer->id, 'status' => 'draft']);

        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => [$reg->id => ['ca' => '5', 'ca2' => '3', 'exam' => '41']]])->assertSessionHasNoErrors();
        $result = Result::firstOrFail();
        $this->assertEquals(49.0, $result->total);
        $this->assertSame('D', $result->grade);

        $this->actingAs($lecturer)->post("/results/{$offering->id}/scores", ['scores' => [$reg->id => ['ca' => '20', 'ca2' => '15', 'exam' => '40']]])->assertSessionHasErrors('scores');
        $this->assertEquals(49.0, $result->fresh()->total, 'the rejected save changed nothing');
    }

    public function test_csv_uploads_accept_four_columns_three_columns_and_named_headers(): void
    {
        Storage::fake('local');
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $c = $this->course('PHY 213');
        foreach (['M1', 'M2', 'M3'] as $m) {
            $this->take($this->student($m), $c, null);
        }
        $offering = CourseOffering::firstOrFail();
        $offering->update(['lecturer_id' => $lecturer->id, 'status' => 'draft']);
        $upload = fn (string $csv) => $this->actingAs($lecturer)->post("/results/{$offering->id}/upload", ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)])->assertSessionHasNoErrors();

        $upload("M1,5,3,41\n");                                  // matric, CA1, CA2, exam
        $upload("M2,12,30\n");                                   // old layout: matric, CA, exam
        $upload("Exam,Matric No,CA 2,CA 1\n41,M3,4,6\n");        // a header decides the order of the columns

        $totals = Result::with('registration.student.user')->get()->mapWithKeys(fn ($r) => [$r->registration->student->user->identifier => [(float) $r->total, $r->ca, $r->ca2]])->all();
        $this->assertSame([49.0, 5.0, 3.0], $totals['M1']);
        $this->assertSame([42.0, 12.0, null], $totals['M2']);
        $this->assertSame([51.0, 6.0, 4.0], $totals['M3']);

        // A word where a score should be is refused, not guessed.
        $this->actingAs($lecturer)->post("/results/{$offering->id}/upload", ['file' => UploadedFile::fake()->createWithContent('s.csv', "M1,5,3,forty\n")])->assertSessionHasErrors('scores');
    }

    // ---- Course result sheet ----

    public function test_the_course_sheet_matches_the_template_layout_data(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER, 'name' => 'Aisha Bello Ademoh']);
        $c = $this->course('PHY 214', 1);
        $this->take($this->student('2061700010'), $c, 66, ca2: 8);   // 66 + CA 2 of 8 = 74, an A
        $this->take($this->student('2061700002'), $c, 35);           // F
        $this->take($this->student('2061200001'), $c, 40);           // E
        $this->take($this->student('2061700003'), $c, 0, flag: 'absent');
        $offering = CourseOffering::firstOrFail();
        $offering->update(['lecturer_id' => $lecturer->id]);

        $data = app(CourseSheetService::class)->build($offering);

        $this->assertSame('PHY 214', $data['course']->code);
        $this->assertSame('Aisha Bello Ademoh', $data['lecturer']);
        $this->assertSame('First', $data['semester']);
        $this->assertSame(['2061200001', '2061700002', '2061700003', '2061700010'], array_column($data['rows'], 'matric'), 'sorted by matric number');
        $this->assertSame([1, 2, 3, 4], array_column($data['rows'], 'sn'));
        $this->assertSame(['Pass', 'Fail', 'Absent', 'Pass'], array_column($data['rows'], 'remark'));
        $this->assertSame(['A' => 1, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 1, 'F' => 2], $data['counts']);
        $this->assertSame(4, $data['total']);
        $this->assertSame('8', $data['rows'][3]['ca2']);
    }

    public function test_the_course_sheet_pdf_is_for_the_lecturer_and_managers_only_and_flags_drafts(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $c = $this->course('PHY 101');
        $this->take($this->student('S1'), $c, 70);
        $offering = CourseOffering::firstOrFail();
        $offering->update(['lecturer_id' => $lecturer->id, 'status' => 'draft']);
        $url = "/results/{$offering->id}/sheet";

        foreach ([$lecturer, $this->eo, $this->hod] as $ok) {
            $response = $this->actingAs($ok)->get($url)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LECTURER]))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->get($url)->assertForbidden();

        $draft = view('pdf.course-sheet', app(CourseSheetService::class)->build($offering))->render();
        $this->assertStringContainsString('DRAFT', $draft);
        $this->assertStringContainsString('EXAMINATION RESULT', $draft);
        $this->assertStringContainsString('Head of Department', $draft);

        $offering->update(['status' => 'approved']);
        $this->assertStringNotContainsString('DRAFT', view('pdf.course-sheet', app(CourseSheetService::class)->build($offering->fresh()))->render());
        $this->assertDatabaseHas('audit_logs', ['action' => 'results.sheet_generated']);
    }

    // ---- Broadsheet figures ----

    public function test_semester_totals_and_remarks_follow_the_senate_format(): void
    {
        $c1 = $this->course('PHY 111', 3);
        $c2 = $this->course('PHY 112', 2);
        $a = $this->student('A');   // passes both
        $b = $this->student('B');   // fails PHY 111
        $c = $this->student('C');   // does not register PHY 112 (core)
        $d = $this->student('D');   // fails PHY 111 and skips PHY 112
        $this->take($a, $c1, 75);   $this->take($a, $c2, 65);
        $this->take($b, $c1, 30);   $this->take($b, $c2, 52);
        $this->take($c, $c1, 60);
        $this->take($d, $c1, 20);

        $sheet = $this->sheet();

        $this->assertSame(['PHY 111', 'PHY 112'], array_column($sheet['courses'], 'code'));
        $this->assertSame([3, 2], array_column($sheet['courses'], 'units'));

        $a = $this->row($sheet, 'A');
        $this->assertSame(['tcr' => 5, 'tce' => 5, 'tgp' => 23, 'gpa' => 4.6], $a['cur']);
        $this->assertSame('PASS', $a['remark']);
        $this->assertSame(['score' => '75', 'gp' => 5], $a['cells'][$c1->id]);

        $b = $this->row($sheet, 'B');
        $this->assertSame(['tcr' => 5, 'tce' => 2, 'tgp' => 6, 'gpa' => 1.2], $b['cur']);
        $this->assertSame('RPT: PHY111', $b['remark']);

        $cc = $this->row($sheet, 'C');
        $this->assertSame('TAKE: PHY112', $cc['remark']);
        $this->assertArrayNotHasKey($c2->id, $cc['cells'], 'not registered shows as a dash');

        $this->assertSame('RPT: PHY111 · TAKE: PHY112', $this->row($sheet, 'D')['remark']);
        $this->assertSame(['pass' => 1, 'repeat' => 1, 'unregistered' => 1, 'both' => 1, 'probation' => 0, 'withdraw' => 0, 'total' => 4], $sheet['summary']);
        $this->assertFalse($sheet['has_previous']);
    }

    public function test_the_average_uses_credits_registered_so_failed_courses_pull_it_down(): void
    {
        $c1 = $this->course('PHY 111', 3);
        $c2 = $this->course('PHY 112', 3);
        $s = $this->student('A');
        $this->take($s, $c1, 80); // A = 5 points
        $this->take($s, $c2, 10); // F = 0 points

        $row = $this->row($this->sheet(), 'A');

        $this->assertSame(['tcr' => 6, 'tce' => 3, 'tgp' => 15, 'gpa' => 2.5], $row['cur']);
    }

    public function test_absent_counts_as_f_and_incomplete_or_withheld_are_left_out(): void
    {
        $abs = $this->course('PHY 111', 3);
        $inc = $this->course('PHY 112', 2);
        $wh = $this->course('PHY 113', 2);
        $ok = $this->course('PHY 114', 3);
        $s = $this->student('A');
        $this->take($s, $abs, 0, flag: 'absent');
        $this->take($s, $inc, null, flag: 'incomplete');
        $this->take($s, $wh, null, flag: 'withheld');
        $this->take($s, $ok, 70);

        $row = $this->row($this->sheet(), 'A');

        $this->assertSame('ABS', $row['cells'][$abs->id]['score']);
        $this->assertSame('INC', $row['cells'][$inc->id]['score']);
        $this->assertSame('WH', $row['cells'][$wh->id]['score']);
        $this->assertSame(['tcr' => 6, 'tce' => 3, 'tgp' => 15, 'gpa' => 2.5], $row['cur']);
    }

    public function test_cumulative_columns_count_every_attempt_but_credits_earned_once(): void
    {
        $c = $this->course('PHY 111', 3);
        $s2 = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
        $student = $this->student('A');
        $this->take($student, $c, 30);                              // fails in 2024/2025
        $this->take($student, $c, 60, $s2);                         // passes the repeat

        $sheet = $this->sheet(100, 1, $s2);
        $row = $this->row($sheet, 'A');

        $this->assertTrue($sheet['has_previous']);
        $this->assertSame(['ctcr' => 3, 'ctce' => 0, 'ctgp' => 0, 'cgpa' => 0.0], $row['prev']);
        $this->assertSame(['tcr' => 3, 'tce' => 3, 'tgp' => 12, 'gpa' => 4.0], $row['cur']);
        $this->assertSame(['ctcr' => 6, 'ctce' => 3, 'ctgp' => 12, 'cgpa' => 2.0], $row['cum']);
        $this->assertSame('PASS', $row['remark'], 'a failed course that was passed later is not to be repeated');
    }

    public function test_second_semester_sheets_carry_the_first_semester_forward(): void
    {
        $first = $this->course('PHY 111', 3, 100, 1);
        $second = $this->course('PHY 121', 3, 100, 2);
        $student = $this->student('A');
        $this->take($student, $first, 70);
        $this->take($student, $second, 55);

        $row = $this->row($this->sheet(100, 2), 'A');

        $this->assertSame(['ctcr' => 3, 'ctce' => 3, 'ctgp' => 15, 'cgpa' => 5.0], $row['prev']);
        $this->assertSame(['tcr' => 3, 'tce' => 3, 'tgp' => 9, 'gpa' => 3.0], $row['cur']);
        $this->assertSame(['ctcr' => 6, 'ctce' => 6, 'ctgp' => 24, 'cgpa' => 4.0], $row['cum']);
    }

    public function test_elective_columns_are_marked_and_other_level_courses_follow_this_levels_courses(): void
    {
        $own = $this->course('PHY 211', 3, 200);
        $elective = $this->course('CSC 211', 2, 200, 1, 'E');
        $lower = $this->course('PHY 111', 3, 100);
        $s = $this->student('A', 200);
        foreach ([$lower, $elective, $own] as $c) {
            $this->take($s, $c, 60, level: 200);
        }

        $columns = $this->sheet(200)['courses'];

        $this->assertSame(['CSC 211', 'PHY 211', 'PHY 111'], array_column($columns, 'code'));
        $this->assertSame([true, false, false], array_column($columns, 'elective'));
    }

    // ---- Which students and results appear ----

    public function test_students_appear_on_the_level_they_were_at_when_they_registered(): void
    {
        $c = $this->course('PHY 111');
        $promoted = $this->student('A', 200); // moved up since
        $this->take($promoted, $c, 60, level: 100);
        $legacy = $this->student('B', 100);   // registration without a stored level: falls back to the current level
        $reg = $this->take($legacy, $c, 60);
        $reg->update(['level_at_registration' => null]);

        $this->assertSame(['A', 'B'], array_column($this->sheet(100)['rows'], 'matric'));
        $this->assertSame([], $this->sheet(200)['rows']);
    }

    public function test_unpublished_courses_are_left_out_and_reported(): void
    {
        $published = $this->course('PHY 111');
        $pending = $this->course('PHY 112');
        $s = $this->student('A');
        $this->take($s, $published, 60);
        $this->take($s, $pending, 60, status: 'approved');

        $sheet = $this->sheet();

        $this->assertSame(['PHY 111'], array_column($sheet['courses'], 'code'));
        $this->assertSame(['PHY 112'], $sheet['unpublished']);
        $this->assertStringContainsString('INCOMPLETE', view('pdf.broadsheet', $sheet + ['generated_by' => 'x', 'generated_at' => now()])->render());
    }

    public function test_pending_registrations_and_other_semesters_do_not_appear(): void
    {
        $c = $this->course('PHY 111');
        $other = $this->course('PHY 121', 3, 100, 2);
        $s = $this->student('A');
        $reg = $this->take($s, $c, 60);
        $this->take($s, $other, 60);
        CourseRegistration::create(['student_profile_id' => $this->student('P')->id, 'course_id' => $c->id, 'academic_session_id' => $this->s1->id, 'semester' => 1, 'level_at_registration' => 100, 'status' => 'pending']);

        $sheet = $this->sheet(100, 1);

        $this->assertSame(['A'], array_column($sheet['rows'], 'matric'));
        $this->assertSame(['PHY 111'], array_column($sheet['courses'], 'code'));
    }

    // ---- TAKE rules ----

    public function test_exempted_and_already_passed_courses_are_not_in_the_take_list(): void
    {
        $exempt = $this->course('PHY 101', 3, 100);
        $passedEarlier = $this->course('PHY 102', 3, 100);
        $missing = $this->course('PHY 103', 3, 100);
        $registered = $this->course('PHY 104', 3, 100);
        $s2 = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
        $transfer = $this->student('T', 100, 'transfer');
        $eval = TransferEvaluation::create(['student_profile_id' => $transfer->id, 'status' => 'approved']);
        CourseExemption::create(['transfer_evaluation_id' => $eval->id, 'student_profile_id' => $transfer->id, 'course_id' => $exempt->id]);
        $this->take($transfer, $passedEarlier, 60);          // earlier session
        $this->take($transfer, $registered, 55, $s2);        // this session

        $row = $this->row($this->sheet(100, 1, $s2), 'T');

        $this->assertSame('TAKE: PHY103', $row['remark']);
    }

    public function test_a_direct_entry_student_is_not_asked_for_100_level_courses_except_gst(): void
    {
        $this->course('PHY 101', 3, 100);
        $gst = $this->course('GST 111', 2, 100);
        $gst->update(['is_gst' => true]);
        $own = $this->course('PHY 201', 3, 200);
        $de = $this->student('DE1', 200, 'direct_entry');
        $this->take($de, $own, 60, level: 200);

        $row = $this->row($this->sheet(200), 'DE1');

        $this->assertSame('TAKE: GST111', $row['remark']);
    }

    // ---- Probation, withdrawal, graduates ----

    public function test_probation_and_withdrawal_apply_at_the_end_of_a_session_only(): void
    {
        $c1 = $this->course('PHY 111', 3, 100, 1);
        $c2 = $this->course('PHY 121', 3, 100, 2);
        $s2 = AcademicSession::create(['name' => '2025/2026']);
        $first = $this->student('FIRST');    // low CGPA this session only
        $again = $this->student('AGAIN');    // low CGPA last session and this session
        $this->take($first, $c1, 25, $s2);      $this->take($first, $c2, 25, $s2);
        $this->take($again, $c1, 25);           $this->take($again, $c2, 25);
        $this->take($again, $c1, 25, $s2);      $this->take($again, $c2, 25, $s2);

        $semesterOne = $this->sheet(100, 1, $s2);
        $this->assertSame(0, $semesterOne['summary']['probation'] + $semesterOne['summary']['withdraw']);

        $end = $this->sheet(100, 2, $s2);
        $this->assertStringStartsWith('PROBATION', $this->row($end, 'FIRST')['remark']);
        $this->assertStringStartsWith('TO WITHDRAW', $this->row($end, 'AGAIN')['remark']);
        $this->assertSame(1, $end['summary']['probation']);
        $this->assertSame(1, $end['summary']['withdraw']);
        $this->assertSame(0, $end['summary']['pass'] + $end['summary']['repeat'], 'probation and withdrawal are counted on their own');
    }

    public function test_four_hundred_level_graduates_show_their_class_and_the_class_table(): void
    {
        $c = $this->course('PHY 421', 3, 400, 2);
        $a = $this->student('G1', 400);
        $b = $this->student('G2', 400);
        $x = $this->student('G3', 400);
        foreach ([$a, $b, $x] as $s) {
            $this->take($s, $c, 80, level: 400);
        }
        foreach ([[$a, 'First Class'], [$b, 'Second Class Lower']] as [$s, $class]) {
            Graduate::create([
                'student_profile_id' => $s->id, 'matric' => $s->user->identifier, 'name' => 'x', 'gender' => 'male', 'entry_mode' => 'regular',
                'graduation_session' => '2024/2025', 'graduation_year' => 2025, 'degree' => 'B.Sc. Physics', 'cgpa' => 4.6,
                'credits_registered' => 3, 'credits_passed' => 3, 'class_of_degree' => $class, 'verification_code' => Str::upper(Str::random(10)), 'approved_at' => now(),
            ]);
        }

        $sheet = $this->sheet(400, 2);

        $this->assertSame('PASS (First Class)', $this->row($sheet, 'G1')['remark']);
        $this->assertSame('PASS (Second Class Lower)', $this->row($sheet, 'G2')['remark']);
        $this->assertSame('PASS', $this->row($sheet, 'G3')['remark']);
        $this->assertSame(['First Class' => 1, 'Second Class Lower' => 1], $sheet['classes']);
        $this->assertNull($this->sheet(400, 1)['classes'], 'the class table belongs to the end-of-session sheet only');
        $this->assertStringContainsString('CLASS OF DEGREE', view('pdf.broadsheet', $sheet + ['generated_by' => 'x', 'generated_at' => now()])->render());
    }

    // ---- The PDF itself ----

    public function test_only_result_managers_open_the_broadsheet_and_it_is_a_pdf(): void
    {
        $c = $this->course('PHY 111');
        $this->take($this->student('A'), $c, 60);
        $query = '?session_id='.$this->s1->id.'&semester=1&level=100';

        foreach ([$this->hod, $this->eo, User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_results']])] as $ok) {
            $this->actingAs($ok)->get('/admin/broadsheets')->assertOk();
            $response = $this->actingAs($ok)->get('/admin/broadsheets/pdf'.$query)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
        foreach ([User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_courses']]), User::factory()->create(['role' => User::ROLE_LECTURER]), User::factory()->create(['role' => User::ROLE_STUDENT])] as $no) {
            $this->actingAs($no)->get('/admin/broadsheets')->assertForbidden();
            $this->actingAs($no)->get('/admin/broadsheets/pdf'.$query)->assertForbidden();
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'broadsheet.generated']);
    }

    public function test_bad_input_and_empty_sheets_are_refused(): void
    {
        $this->actingAs($this->eo)->get('/admin/broadsheets/pdf')->assertSessionHasErrors(['session_id', 'semester', 'level']);
        $this->actingAs($this->eo)->get('/admin/broadsheets/pdf?session_id='.$this->s1->id.'&semester=3&level=500')->assertSessionHasErrors(['semester', 'level']);
        $this->actingAs($this->eo)->get('/admin/broadsheets/pdf?session_id='.$this->s1->id.'&semester=1&level=100')->assertNotFound();
    }

    public function test_the_template_header_footer_and_signature_blocks_are_present(): void
    {
        $c = $this->course('PHY 111');
        $this->take($this->student('A'), $c, 60);

        $html = view('pdf.broadsheet', $this->sheet() + ['generated_by' => 'Tester', 'generated_at' => now()])->render();

        foreach ([
            'EXAM BROADSHEET, FIRST SEMESTER, 2024/2025', 'LEVEL: 100 LEVEL', 'DEPARTMENT:', 'MAT. NO.', 'REMARKS', 'TCR', 'TCE', 'TGP', 'GPA',
            'NO. PASS', 'NO. TO REPEAT COURSES', 'NO. WITH UNREGISTERED COURSES', 'NO. WITH REPEAT &amp; UNREGISTERED COURSES',
            'NO. ON PROBATION', 'NO. TO WITHDRAW', 'HEAD OF DEPARTMENT:', 'DEAN:', 'NAME/SIGN', 'LEGEND:', 'Not registered',
        ] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('PREVIOUS CUMULATIVE', $html, 'a first semester sheet has no previous block');
    }

    public function test_a_wide_sheet_with_many_courses_still_renders_a_pdf(): void
    {
        $student = $this->student('A', 200);
        foreach (range(1, 30) as $i) {
            $this->take($student, $this->course(sprintf('PHY %03d', 200 + $i), 2, 200), 40 + $i, level: 200);
        }
        $sheet = $this->sheet(200);
        $this->assertGreaterThan(1040, $sheet['width']);

        $response = $this->actingAs($this->eo)->get('/admin/broadsheets/pdf?session_id='.$this->s1->id.'&semester=1&level=200')->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
