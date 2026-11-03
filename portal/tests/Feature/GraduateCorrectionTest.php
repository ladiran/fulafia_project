<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Graduate;
use App\Models\GraduateAmendment;
use App\Models\GraduateResult;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\BroadsheetService;
use App\Services\GraduateAmendmentService;
use App\Services\TranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GraduateCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $hod;
    private User $eo;
    private User $alumnus;
    private Graduate $graduate;

    /** @var array<string, GraduateResult> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN, 'name' => 'Prof. Head']);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN, 'name' => 'Mr. EO']);
        $this->alumnus = User::factory()->create(['role' => User::ROLE_ALUMNI, 'name' => 'Ada Obi', 'identifier' => 'FUL/PHY/21/001']);
        $profile = StudentProfile::create([
            'user_id' => $this->alumnus->id, 'entry_mode' => 'regular', 'entry_level' => 100, 'gender' => 'female', 'current_level' => 400, 'status' => 'graduated',
        ]);

        // PHY 101 70 (A), PHY 102 60 (B), PHY 103 30 (F): TCU 8, TGP 27, CGPA 3.38, 6 credits passed.
        $this->graduate = Graduate::create([
            'student_profile_id' => $profile->id, 'matric' => 'FUL/PHY/21/001', 'name' => 'Ada Obi', 'gender' => 'female', 'entry_mode' => 'regular',
            'entry_session' => '2021/2022', 'graduation_session' => '2024/2025', 'graduation_year' => 2025, 'degree' => 'B.Sc. Physics',
            'specialization' => 'Geophysics', 'cgpa' => 3.38, 'credits_registered' => 8, 'credits_passed' => 6, 'class_of_degree' => 'Second Class Lower',
            'verification_code' => 'ABCDE12345', 'approved_by' => $this->hod->id, 'approved_at' => now(),
        ]);
        foreach ([
            ['PHY 101', 3, 20, null, 50, 70, 'A', 5, false, false],
            ['PHY 102', 3, 18, null, 42, 60, 'B', 4, false, false],
            ['PHY 103', 2, 9, null, 21, 30, 'F', 0, false, false],
            ['CSC 101', 2, null, null, null, null, null, null, true, false],
            ['MTH 101', 3, null, null, null, null, null, null, false, true],
        ] as [$code, $units, $ca, $ca2, $exam, $total, $grade, $gp, $waived, $exempted]) {
            $this->rows[$code] = GraduateResult::create([
                'graduate_id' => $this->graduate->id, 'session' => '2024/2025', 'semester' => 1, 'course_code' => $code, 'course_title' => "Title {$code}",
                'units' => $units, 'course_type' => 'C', 'ca' => $ca, 'ca2' => $ca2, 'exam' => $exam, 'total' => $total, 'grade' => $grade,
                'grade_point' => $gp, 'waived' => $waived, 'exempted' => $exempted,
            ]);
        }
    }

    private function service(): GraduateAmendmentService
    {
        return app(GraduateAmendmentService::class);
    }

    private function request(User $by, array $data)
    {
        return $this->actingAs($by)->post("/admin/graduates/{$this->graduate->id}/corrections", $data + ['reason' => 'Verified against the examination records']);
    }

    private function approve(GraduateAmendment $a, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->hod)->post("/admin/corrections/{$a->id}/approve");
    }

    private function effective(): Graduate
    {
        return $this->graduate->fresh()->effective();
    }

    // ---- Access ----

    public function test_only_the_eo_and_hod_can_ask_and_only_the_hod_decides(): void
    {
        $data = ['kind' => 'field', 'field' => 'name', 'value' => 'Ada Okafor'];

        foreach ([User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students', 'manage_content', 'manage_results']]),
            User::factory()->create(['role' => User::ROLE_LECTURER]), $this->alumnus] as $no) {
            $this->request($no, $data)->assertForbidden();
        }
        $this->request($this->eo, $data)->assertSessionHasNoErrors();
        $amendment = GraduateAmendment::firstOrFail();

        $this->approve($amendment, $this->eo)->assertForbidden();
        $this->actingAs($this->eo)->post("/admin/corrections/{$amendment->id}/reject", ['note' => 'x'])->assertForbidden();
        $this->assertSame('requested', $amendment->fresh()->status);

        $this->actingAs($this->eo)->get('/admin/corrections')->assertOk()->assertSee('Waiting for the HoD');
        $this->approve($amendment)->assertSessionHasNoErrors();
        $this->assertSame('approved', $amendment->fresh()->status);
    }

    // ---- Field corrections ----

    public function test_a_corrected_detail_takes_effect_only_after_approval_and_the_original_is_kept(): void
    {
        $this->request($this->eo, ['kind' => 'field', 'field' => 'name', 'value' => 'Ada Okafor']);
        $amendment = GraduateAmendment::firstOrFail();

        $this->assertSame('Ada Obi', $this->effective()->name, 'nothing changes while the request waits');
        $this->assertTrue($this->hod->notifications()->where('data->title', 'Graduate record correction to approve')->exists());

        $this->approve($amendment);

        $this->assertSame('Ada Okafor', $this->effective()->name);
        $this->assertSame('Ada Obi', Graduate::find($this->graduate->id)->getRawOriginal('name'), 'the permanent row is untouched');
        $amendment->refresh();
        $this->assertSame(['value' => 'Ada Obi'], $amendment->old_value);
        $this->assertSame(['value' => 'Ada Okafor'], $amendment->new_value);
        $this->assertSame($this->hod->id, $amendment->decided_by);
        $this->assertSame($this->eo->id, $amendment->requested_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'graduate.correction_requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'graduate.correction_approved']);
        $this->assertTrue($this->eo->notifications()->where('data->title', 'Correction approved')->exists());
    }

    public function test_a_corrected_name_also_updates_the_alumni_account(): void
    {
        $this->request($this->eo, ['kind' => 'field', 'field' => 'name', 'value' => 'Ada Okafor']);
        $this->approve(GraduateAmendment::firstOrFail());

        $this->assertSame('Ada Okafor', $this->alumnus->fresh()->name);
        $this->assertSame('FUL/PHY/21/001', $this->alumnus->fresh()->identifier);
    }

    public function test_the_corrected_record_is_what_everyone_sees(): void
    {
        foreach ([['name', 'Ada Okafor'], ['specialization', 'Photonics'], ['graduation_year', '2024']] as [$field, $value]) {
            Specialization::firstOrCreate(['name' => 'Photonics']);
            $this->request($this->eo, ['kind' => 'field', 'field' => $field, 'value' => $value]);
        }
        GraduateAmendment::all()->each(fn ($a) => $this->approve($a));

        // Public verification, the alumni statement and the departmental transcript.
        $this->get('/verify?code=ABCDE12345')->assertSee('Ada Okafor')->assertSee('2024')->assertDontSee('Ada Obi');
        $this->actingAs($this->alumnus->fresh())->get('/student/statement')->assertSee('Ada Okafor')->assertSee('Photonics')->assertSee('corrected 3 time(s)');
        $this->actingAs($this->hod)->get("/admin/graduates/{$this->graduate->id}")->assertSee('Ada Okafor');
        $data = app(TranscriptService::class)->forGraduate($this->graduate);
        $this->assertSame('Ada Okafor', $data['person']['name']);
        $this->assertSame('Photonics', $data['person']['specialization']);
        $this->assertCount(3, $data['corrections']);
        $this->assertStringContainsString('Corrections to this record', view('pdf.transcript', $data + ['generated_by' => 'x', 'generated_at' => now()])->render());
    }

    public function test_the_register_list_shows_corrected_values_and_finds_a_corrected_name(): void
    {
        $this->request($this->eo, ['kind' => 'field', 'field' => 'name', 'value' => 'Ada Okafor']);
        $this->approve(GraduateAmendment::firstOrFail());

        $this->actingAs($this->eo)->get('/admin/graduates?q=Okafor')->assertSee('Ada Okafor')->assertSee('FUL/PHY/21/001');
        // The original name still finds the record, but the list shows the corrected one.
        $this->actingAs($this->eo)->get('/admin/graduates?q=Obi')->assertSee('FUL/PHY/21/001')->assertDontSee('Ada Obi');
    }

    public function test_detail_corrections_are_validated(): void
    {
        $bad = fn (array $d) => $this->request($this->eo, ['kind' => 'field'] + $d);

        $bad(['field' => 'cgpa', 'value' => '5'])->assertSessionHasErrors('field');                 // derived, not editable
        $bad(['field' => 'class_of_degree', 'value' => 'First Class'])->assertSessionHasErrors('field');
        $bad(['field' => 'verification_code', 'value' => 'X'])->assertSessionHasErrors('field');
        $bad(['field' => 'name', 'value' => 'Ada Obi'])->assertSessionHasErrors('value');          // no change
        $bad(['field' => 'gender', 'value' => 'other'])->assertSessionHasErrors('value');
        $bad(['field' => 'graduation_year', 'value' => '1800'])->assertSessionHasErrors('value');
        $bad(['field' => 'graduation_session', 'value' => '2025'])->assertSessionHasErrors('value');
        $bad(['field' => 'specialization', 'value' => 'Astrology'])->assertSessionHasErrors('value');
        $this->request($this->eo, ['kind' => 'field', 'field' => 'name', 'value' => 'Ada Okafor', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(0, GraduateAmendment::count());
    }

    public function test_a_matric_number_cannot_be_corrected_to_one_that_is_already_in_use(): void
    {
        $other = StudentProfile::create(['user_id' => User::factory()->create(['role' => User::ROLE_ALUMNI, 'identifier' => 'FUL/PHY/21/002'])->id,
            'entry_mode' => 'regular', 'entry_level' => 100, 'gender' => 'male', 'current_level' => 400, 'status' => 'graduated']);
        Graduate::create(['student_profile_id' => $other->id, 'matric' => 'FUL/PHY/21/002', 'name' => 'Other', 'gender' => 'male', 'entry_mode' => 'regular',
            'graduation_session' => '2024/2025', 'graduation_year' => 2025, 'degree' => 'B.Sc.', 'cgpa' => 3.0, 'credits_registered' => 3, 'credits_passed' => 3,
            'class_of_degree' => 'Second Class Lower', 'verification_code' => 'ZZZZZ99999', 'approved_at' => now()]);
        User::factory()->create(['identifier' => 'FUL/PHY/21/777']);

        $this->request($this->eo, ['kind' => 'field', 'field' => 'matric', 'value' => 'FUL/PHY/21/002'])->assertSessionHasErrors('value');
        $this->request($this->eo, ['kind' => 'field', 'field' => 'matric', 'value' => 'FUL/PHY/21/777'])->assertSessionHasErrors('value');
        $this->request($this->eo, ['kind' => 'field', 'field' => 'matric', 'value' => 'FUL/PHY/21/003'])->assertSessionHasNoErrors();
        $this->approve(GraduateAmendment::firstOrFail());

        $this->assertSame('FUL/PHY/21/003', $this->effective()->matric);
        $this->get('/verify?code=ABCDE12345')->assertSee('FUL/PHY/21/003');
    }

    // ---- Score corrections ----

    public function test_a_corrected_score_recalculates_the_grade_cgpa_credits_and_class(): void
    {
        $r = $this->rows['PHY 103'];
        $this->request($this->eo, ['kind' => 'result', 'graduate_result_id' => $r->id, 'ca' => '15', 'exam' => '40'])->assertSessionHasNoErrors();
        $amendment = GraduateAmendment::firstOrFail();

        $before = $this->effective();
        $this->assertSame('Second Class Lower', $before->class_of_degree);

        $this->approve($amendment)->assertSessionHasNoErrors();

        $after = $this->effective();
        $row = $after->results->firstWhere('id', $r->id);
        $this->assertEquals(55.0, $row->total);
        $this->assertSame('C', $row->grade);
        $this->assertSame(3, $row->grade_point);
        $this->assertTrue($row->getAttribute('corrected'));
        // TGP 15 + 12 + 6 = 33 over 8 units = 4.125, rounded to 4.13: now Second Class Upper. The failed course is passed, so 8 credits.
        $this->assertEquals(4.13, $after->cgpa);
        $this->assertSame(8, $after->credits_passed);
        $this->assertSame('Second Class Upper', $after->class_of_degree);
        // Other rows and the permanent snapshot are untouched.
        $this->assertSame('A', $after->results->firstWhere('id', $this->rows['PHY 101']->id)->grade);
        $this->assertSame('F', GraduateResult::find($r->id)->grade);
        $this->assertSame(3.38, (float) Graduate::find($this->graduate->id)->getRawOriginal('cgpa'));
        $this->assertEquals(['ca' => 9, 'ca2' => null, 'exam' => 21, 'flag' => null], $amendment->fresh()->old_value);
    }

    public function test_a_score_correction_can_lift_the_class_of_degree(): void
    {
        $r = $this->rows['PHY 103'];
        $this->request($this->hod, ['kind' => 'result', 'graduate_result_id' => $r->id, 'ca' => '20', 'ca2' => '5', 'exam' => '60']);
        $this->approve(GraduateAmendment::firstOrFail());

        $after = $this->effective();

        $this->assertEquals(85.0, $after->results->firstWhere('id', $r->id)->total, 'CA 1 + CA 2 + exam');
        $this->assertEquals(4.63, $after->cgpa);          // (15 + 12 + 10) / 8 = 4.625
        $this->assertSame('First Class', $after->class_of_degree);
        $this->get('/verify?code=ABCDE12345')->assertSee('First Class')->assertDontSee('Second Class Lower');
    }

    public function test_a_flag_can_be_corrected_and_withheld_courses_leave_the_gpa(): void
    {
        $r = $this->rows['PHY 103'];
        $this->request($this->eo, ['kind' => 'result', 'graduate_result_id' => $r->id, 'flag' => 'withheld']);
        $this->approve(GraduateAmendment::firstOrFail());

        $after = $this->effective();

        $this->assertNull($after->results->firstWhere('id', $r->id)->grade);
        $this->assertEquals(4.5, $after->cgpa);           // (15 + 12) / 6
        $this->assertSame('First Class', $after->class_of_degree);
    }

    public function test_score_corrections_are_validated(): void
    {
        $r = $this->rows['PHY 103'];
        $ok = ['kind' => 'result', 'graduate_result_id' => $r->id, 'ca' => '10', 'exam' => '40'];

        $this->request($this->eo, ['ca' => '31'] + $ok)->assertSessionHasErrors('exam');                       // over the CA limit
        $this->request($this->eo, ['ca' => '20', 'ca2' => '15'] + $ok)->assertSessionHasErrors('exam');       // CA 1 + CA 2 over the limit
        $this->request($this->eo, ['exam' => '71'] + $ok)->assertSessionHasErrors('exam');
        $this->request($this->eo, ['ca' => 'abc'] + $ok)->assertSessionHasErrors('exam');
        $this->request($this->eo, ['exam' => ''] + $ok)->assertSessionHasErrors('exam');                       // exam needed unless a flag
        $this->request($this->eo, ['ca' => '9', 'exam' => '21'] + $ok)->assertSessionHasErrors('exam');       // same as the record
        $this->request($this->eo, ['flag' => 'gone'] + $ok)->assertSessionHasErrors('flag');
        $this->request($this->eo, ['graduate_result_id' => $this->rows['CSC 101']->id] + $ok)->assertSessionHasErrors('graduate_result_id');   // waived
        $this->request($this->eo, ['graduate_result_id' => $this->rows['MTH 101']->id] + $ok)->assertSessionHasErrors('graduate_result_id');   // exempted
        $this->request($this->eo, ['graduate_result_id' => 99999] + $ok)->assertSessionHasErrors('graduate_result_id');
        $this->assertSame(0, GraduateAmendment::count());
    }

    public function test_a_row_of_another_graduate_cannot_be_corrected_through_this_record(): void
    {
        $profile = StudentProfile::create(['user_id' => User::factory()->create(['role' => User::ROLE_ALUMNI])->id, 'entry_mode' => 'regular',
            'entry_level' => 100, 'gender' => 'male', 'current_level' => 400, 'status' => 'graduated']);
        $other = Graduate::create(['student_profile_id' => $profile->id, 'matric' => 'M2', 'name' => 'Other', 'gender' => 'male', 'entry_mode' => 'regular',
            'graduation_session' => '2024/2025', 'graduation_year' => 2025, 'degree' => 'B.Sc.', 'cgpa' => 3.0, 'credits_registered' => 3, 'credits_passed' => 3,
            'class_of_degree' => 'Second Class Lower', 'verification_code' => 'OTHER00001', 'approved_at' => now()]);
        $foreign = GraduateResult::create(['graduate_id' => $other->id, 'session' => '2024/2025', 'semester' => 1, 'course_code' => 'PHY 999', 'course_title' => 'x',
            'units' => 3, 'course_type' => 'C', 'ca' => 10, 'exam' => 50, 'total' => 60, 'grade' => 'B', 'grade_point' => 4]);

        $this->request($this->eo, ['kind' => 'result', 'graduate_result_id' => $foreign->id, 'ca' => '30', 'exam' => '70'])->assertSessionHasErrors('graduate_result_id');
    }

    public function test_a_correction_cannot_take_the_cgpa_below_the_lowest_pass(): void
    {
        // Everything fails: 8 units and 0 grade points.
        foreach (['PHY 101', 'PHY 102'] as $code) {
            $this->request($this->eo, ['kind' => 'result', 'graduate_result_id' => $this->rows[$code]->id, 'ca' => '1', 'exam' => '1']);
        }
        $first = GraduateAmendment::first();
        $this->approve($first); // PHY 101 down to 2: (0*3 + 12 + 0) / 8 = 1.5, still above 1.00

        $second = GraduateAmendment::orderByDesc('id')->first();
        $this->approve($second)->assertSessionHasErrors('exam'); // 0 + 0 + 0 / 8 = 0.00: refused at approval

        $this->assertSame('requested', $second->fresh()->status);
        $this->assertEquals(1.5, $this->effective()->cgpa);
    }

    public function test_two_corrections_to_the_same_score_apply_in_order_and_record_what_was_there_before(): void
    {
        $r = $this->rows['PHY 103'];
        $this->request($this->eo, ['kind' => 'result', 'graduate_result_id' => $r->id, 'ca' => '15', 'exam' => '40']);
        $first = GraduateAmendment::firstOrFail();
        $this->approve($first);
        $this->request($this->eo, ['kind' => 'result', 'graduate_result_id' => $r->id, 'ca' => '20', 'exam' => '60']);
        $second = GraduateAmendment::orderByDesc('id')->first();
        $this->approve($second);

        $this->assertEquals(80.0, $this->effective()->results->firstWhere('id', $r->id)->total);
        $this->assertEquals(['ca' => 15, 'ca2' => null, 'exam' => 40, 'flag' => null], $second->fresh()->old_value, 'the second correction records the first one as "before"');
        $this->assertEquals(['ca' => 9, 'ca2' => null, 'exam' => 21, 'flag' => null], $first->fresh()->old_value);
    }

    // ---- Rejection and immutability ----

    public function test_a_rejected_correction_changes_nothing_and_needs_a_reason(): void
    {
        $this->request($this->eo, ['kind' => 'field', 'field' => 'name', 'value' => 'Wrong Person']);
        $amendment = GraduateAmendment::firstOrFail();

        $this->actingAs($this->hod)->post("/admin/corrections/{$amendment->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($this->hod)->post("/admin/corrections/{$amendment->id}/reject", ['note' => 'No evidence'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $amendment->fresh()->status);
        $this->assertSame('Ada Obi', $this->effective()->name);
        $this->assertSame('Ada Obi', $this->alumnus->fresh()->name);
        $this->assertTrue($this->eo->notifications()->where('data->title', 'Correction not approved')->exists());
    }

    public function test_a_decided_correction_cannot_be_decided_again_or_changed_or_deleted(): void
    {
        $this->request($this->eo, ['kind' => 'field', 'field' => 'name', 'value' => 'Ada Okafor']);
        $amendment = GraduateAmendment::firstOrFail();
        $this->approve($amendment);

        $this->approve($amendment)->assertSessionHasErrors('correction');
        $this->actingAs($this->hod)->post("/admin/corrections/{$amendment->id}/reject", ['note' => 'x'])->assertSessionHasErrors('correction');

        try {
            $amendment->fresh()->update(['reason' => 'tampered']);
            $this->fail('a decided correction must not be editable');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        $amendment->fresh()->delete();
    }

    public function test_the_permanent_rows_themselves_still_cannot_be_edited(): void
    {
        $this->expectException(\LogicException::class);
        $this->graduate->update(['name' => 'Direct edit']);
    }

    // ---- Other places that read the record ----

    public function test_the_broadsheet_uses_the_corrected_class_of_degree(): void
    {
        $session = AcademicSession::create(['name' => '2024/2025']);
        $course = \App\Models\Course::create(['code' => 'PHY 421', 'title' => 'x', 'units' => 3, 'level' => 400, 'semester' => 2, 'type' => 'C']);
        $reg = \App\Models\CourseRegistration::create(['student_profile_id' => $this->graduate->student_profile_id, 'course_id' => $course->id,
            'academic_session_id' => $session->id, 'semester' => 2, 'level_at_registration' => 400, 'status' => 'approved']);
        $offering = \App\Models\CourseOffering::create(['course_id' => $course->id, 'academic_session_id' => $session->id, 'status' => 'published']);
        \App\Models\Result::create(['course_registration_id' => $reg->id, 'course_offering_id' => $offering->id, 'ca' => 20, 'exam' => 50, 'total' => 70, 'grade' => 'A', 'grade_point' => 5]);

        $r = $this->rows['PHY 103'];
        $this->request($this->hod, ['kind' => 'result', 'graduate_result_id' => $r->id, 'ca' => '20', 'ca2' => '5', 'exam' => '60']);
        $this->approve(GraduateAmendment::firstOrFail());

        $sheet = app(BroadsheetService::class)->build($session, 2, 400);

        $this->assertSame('PASS (First Class)', $sheet['rows'][0]['remark']);
        $this->assertSame(['First Class' => 1], $sheet['classes']);
    }

    public function test_the_correction_pages_show_the_history_and_the_forms(): void
    {
        $this->request($this->eo, ['kind' => 'field', 'field' => 'name', 'value' => 'Ada Okafor']);

        $this->actingAs($this->eo)->get("/admin/graduates/{$this->graduate->id}")->assertOk()
            ->assertSee('Correct a detail')->assertSee('Correct a score')->assertSee('Ada Okafor')->assertSee('REQUESTED')->assertDontSee('Approve</button>', false);
        $this->actingAs($this->hod)->get("/admin/graduates/{$this->graduate->id}")->assertSee('Approve');
        $this->actingAs($this->hod)->get('/admin/corrections')->assertOk()->assertSee('FUL/PHY/21/001')->assertSee('Verified against the examination records');
    }

    public function test_the_effective_record_is_never_saved_back(): void
    {
        $effective = $this->effective();
        $effective->setAttribute('name', 'Changed in memory');

        $this->expectException(\LogicException::class);
        $effective->save();
    }

    public function test_a_record_with_no_corrections_reads_exactly_as_stored(): void
    {
        $effective = $this->effective();

        $this->assertSame('Ada Obi', $effective->name);
        $this->assertEquals(3.38, $effective->cgpa);
        $this->assertSame(6, (int) $effective->credits_passed);
        $this->assertSame('Second Class Lower', $effective->class_of_degree);
        $this->assertFalse($effective->isAmended());
        $this->assertSame([], app(TranscriptService::class)->forGraduate($this->graduate)['corrections']);
        $this->assertNotNull(Str::of('x'));
    }
}
