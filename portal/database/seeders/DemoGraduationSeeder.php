<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\GraduationRequirement;
use App\Models\Result;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\GradingService;
use Illuminate\Database\Seeder;

/**
 * Optional demo: php artisan db:seed --class=DemoGraduationSeeder
 * Gives the demo final-year student published results for every core course and the Geophysics
 * elective, plus one failed ordinary elective so the HoD's "Waive" button can be tried.
 */
class DemoGraduationSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'final@physics.test')->firstOrFail();
        $student = $user->studentProfile;
        $session = AcademicSession::current();
        $student->update(['specialization_id' => Specialization::where('name', 'Geophysics')->value('id')]);

        // Demo credit requirement matching the small sample curriculum.
        GraduationRequirement::updateOrCreate(['entry_mode' => 'regular'], ['min_credits_registered' => 20, 'min_credits_passed' => 20]);

        $grading = app(GradingService::class);
        $courses = Course::where('is_active', true)->where(fn ($q) => $q->where('type', 'C')->orWhere('code', 'PHY 411')->orWhere('code', 'CSC 101'))->get();

        foreach ($courses as $course) {
            $total = $course->code === 'CSC 101' ? 30 : 72;
            $registration = CourseRegistration::firstOrCreate(
                ['student_profile_id' => $student->id, 'course_id' => $course->id, 'academic_session_id' => $session->id],
                ['semester' => $course->semester, 'status' => 'approved'],
            );
            $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'academic_session_id' => $session->id], ['status' => 'published']);
            $offering->update(['status' => 'published']);
            $ca = round($total * 0.3, 2);
            $exam = round($total - $ca, 2);
            Result::updateOrCreate(
                ['course_registration_id' => $registration->id],
                ['course_offering_id' => $offering->id, 'ca' => $ca, 'exam' => $exam] + $grading->evaluate($ca, $exam, null),
            );
        }
    }
}
