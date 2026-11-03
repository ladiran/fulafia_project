<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\LevelAdviserAppointment;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(['role' => $role] + $extra);
    }

    private function student(int $level = 100, string $mode = 'regular', ?int $area = null): StudentProfile
    {
        $user = $this->user(User::ROLE_STUDENT);

        return StudentProfile::create([
            'user_id' => $user->id, 'entry_mode' => $mode, 'entry_level' => $mode === 'direct_entry' ? 200 : 100,
            'gender' => 'female', 'current_level' => $level, 'specialization_id' => $area,
        ]);
    }

    private function course(string $code, int $level, string $type = 'C', array $extra = []): Course
    {
        return Course::create($extra + ['code' => $code, 'title' => $code, 'units' => 3, 'level' => $level, 'semester' => 1, 'type' => $type]);
    }

    public function test_course_management_needs_the_permission(): void
    {
        $plain = $this->user(User::ROLE_ADMIN, ['permissions' => ['publish_news']]);
        $granted = $this->user(User::ROLE_ADMIN, ['permissions' => ['manage_courses']]);

        $this->actingAs($plain)->get('/admin/courses')->assertForbidden();
        $this->actingAs($granted)->get('/admin/courses')->assertOk();
        $this->actingAs($granted)->get('/admin/students')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_SECONDARY_ADMIN))->get('/admin/courses')->assertOk();
    }

    public function test_only_the_hod_manages_specializations(): void
    {
        $this->actingAs($this->user(User::ROLE_SECONDARY_ADMIN))->post('/admin/specializations', ['name' => 'Optics'])->assertForbidden();
        $this->actingAs($this->user(User::ROLE_PRINCIPAL_ADMIN))->post('/admin/specializations', ['name' => 'Optics'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('specializations', ['name' => 'Optics']);
    }

    public function test_student_is_admitted_at_the_right_level_with_a_university_matric_number(): void
    {
        $eo = $this->user(User::ROLE_SECONDARY_ADMIN);
        $base = ['name' => 'Ada Obi', 'gender' => 'female', 'entry_session_id' => $this->session->id, 'jamb_reg_no' => '2025123456AB'];

        $this->actingAs($eo)->post('/admin/students', $base + ['identifier' => 'FUL/PHY/25/001', 'entry_mode' => 'direct_entry'])->assertSessionHasNoErrors();
        $this->assertSame(200, StudentProfile::firstOrFail()->current_level);

        // Matric numbers are unique, and a JAMB number is required for JAMB admissions.
        $this->actingAs($eo)->post('/admin/students', $base + ['identifier' => 'FUL/PHY/25/001', 'entry_mode' => 'regular'])->assertSessionHasErrors('identifier');
        $this->actingAs($eo)->post('/admin/students', ['jamb_reg_no' => ''] + $base + ['identifier' => 'FUL/PHY/25/002', 'entry_mode' => 'regular'])->assertSessionHasErrors('jamb_reg_no');
        $this->actingAs($eo)->post('/admin/students', $base + ['identifier' => 'FUL/PHY/25/003', 'entry_mode' => 'transfer'])->assertSessionHasErrors('entry_level');
    }

    public function test_only_the_hod_can_correct_a_students_name(): void
    {
        $student = $this->student();
        $payload = ['name' => 'New Name', 'current_level' => 100, 'status' => 'active'];

        $this->actingAs($this->user(User::ROLE_SECONDARY_ADMIN))->put("/admin/students/{$student->id}", $payload);
        $this->assertNotSame('New Name', $student->user->fresh()->name);

        $this->actingAs($this->user(User::ROLE_PRINCIPAL_ADMIN))->put("/admin/students/{$student->id}", $payload);
        $this->assertSame('New Name', $student->user->fresh()->name);
    }

    public function test_registration_requires_all_core_courses_and_stores_pending(): void
    {
        $student = $this->student(100);
        $core1 = $this->course('PHY 101', 100);
        $core2 = $this->course('PHY 102', 100);
        $elective = $this->course('CSC 101', 100, 'E');

        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core1->id]])->assertSessionHasErrors('courses');
        $this->assertSame(0, CourseRegistration::count());

        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core1->id, $core2->id, $elective->id]])->assertSessionHasNoErrors();
        $this->assertSame(3, CourseRegistration::where('status', 'pending')->count());
    }

    public function test_courses_from_other_levels_cannot_be_registered(): void
    {
        $student = $this->student(100);
        $core = $this->course('PHY 101', 100);
        $other = $this->course('PHY 301', 300);

        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id, $other->id]])->assertSessionHasErrors('courses');
    }

    public function test_direct_entry_student_also_gets_100_level_gst_courses_only(): void
    {
        $student = $this->student(200, 'direct_entry');
        $gst = $this->course('GST 111', 100, 'C', ['is_gst' => true]);
        $phy100 = $this->course('PHY 101', 100);
        $phy200 = $this->course('PHY 201', 200);

        $ids = app(\App\Services\RegistrationService::class)->availableCourses($student, $this->session)->pluck('id');

        $this->assertTrue($ids->contains($gst->id));
        $this->assertTrue($ids->contains($phy200->id));
        $this->assertFalse($ids->contains($phy100->id));
    }

    public function test_400_level_needs_a_specialization_and_its_linked_electives_are_mandatory(): void
    {
        $geo = Specialization::create(['name' => 'Geophysics']);
        $nuclear = Specialization::create(['name' => 'Nuclear Physics']);
        $core = $this->course('PHY 401', 400);
        $geoCourse = $this->course('PHY 411', 400, 'E', ['specialization_id' => $geo->id]);
        $nuclearCourse = $this->course('PHY 421', 400, 'E', ['specialization_id' => $nuclear->id]);

        $student = $this->student(400);
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertForbidden();

        $student->update(['specialization_id' => $geo->id]);
        $student->user->unsetRelations(); // the earlier request cached the profile on this user object
        $service = app(\App\Services\RegistrationService::class);
        $ids = $service->availableCourses($student, $this->session)->pluck('id');
        $this->assertTrue($ids->contains($geoCourse->id));
        $this->assertFalse($ids->contains($nuclearCourse->id));

        // The Geophysics elective is mandatory for this student.
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id]])->assertSessionHasErrors('courses');
        $this->actingAs($student->user)->post('/student/registration', ['courses' => [$core->id, $geoCourse->id]])->assertSessionHasNoErrors();
    }

    public function test_specialization_can_be_chosen_once_only_while_the_window_is_open(): void
    {
        $geo = Specialization::create(['name' => 'Geophysics']);
        $student = $this->student(400);

        $this->actingAs($student->user)->post('/student/specialization', ['specialization_id' => $geo->id])->assertForbidden();

        $this->session->update(['specialization_window_open' => true]);
        $this->actingAs($student->user)->post('/student/specialization', ['specialization_id' => $geo->id])->assertSessionHasNoErrors();
        $this->assertSame($geo->id, $student->fresh()->specialization_id);

        $other = Specialization::create(['name' => 'Photonics']);
        $this->actingAs($student->user)->post('/student/specialization', ['specialization_id' => $other->id])->assertForbidden();
    }

    public function test_level_adviser_sees_only_their_level_and_approves_registrations(): void
    {
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $adviser = $this->user(User::ROLE_LECTURER);
        LevelAdviserAppointment::create([
            'user_id' => $adviser->id, 'level' => 100, 'starts_on' => today(), 'ends_on' => today()->addYears(2), 'appointed_by' => $hod->id,
        ]);

        $mine = $this->student(100);
        $other = $this->student(200);
        $course = $this->course('PHY 101', 100);
        $reg = CourseRegistration::create([
            'student_profile_id' => $mine->id, 'course_id' => $course->id, 'academic_session_id' => $this->session->id, 'semester' => 1,
        ]);

        $this->actingAs($adviser)->get('/adviser/students')->assertOk()->assertSee($mine->user->name)->assertDontSee($other->user->name)->assertSee('1 pending');
        $this->actingAs($adviser)->get("/adviser/students/{$other->id}")->assertForbidden();

        $this->actingAs($adviser)->post("/adviser/registrations/{$reg->id}", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $reg->fresh()->status);
        $this->assertSame($adviser->id, $reg->fresh()->reviewed_by);
    }

    public function test_a_lecturer_who_is_not_an_adviser_has_no_access(): void
    {
        $this->actingAs($this->user(User::ROLE_LECTURER))->get('/adviser/students')->assertForbidden();
    }

    public function test_an_expired_adviser_keeps_access_until_removed(): void
    {
        $hod = $this->user(User::ROLE_PRINCIPAL_ADMIN);
        $adviser = $this->user(User::ROLE_LECTURER);
        $appointment = LevelAdviserAppointment::create([
            'user_id' => $adviser->id, 'level' => 100, 'starts_on' => today()->subYears(3), 'ends_on' => today()->subYear(), 'appointed_by' => $hod->id,
        ]);

        $this->actingAs($adviser)->get('/adviser/students')->assertOk();

        $appointment->update(['removed_at' => now(), 'removed_by' => $hod->id]);
        $this->actingAs($adviser)->get('/adviser/students')->assertForbidden();
    }
}

