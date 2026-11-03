<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development data. The course list below is a SAMPLE for trying the system out; replace it
 * with the department's approved curriculum through Admin > Courses.
 */
class AcademicSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Atmospheric Physics', 'Quantum Information Theory', 'Geophysics', 'Photonics',
            'Material Physics', 'Nuclear Physics', 'Radiation & Biophysics',
        ] as $name) {
            Specialization::firstOrCreate(['name' => $name]);
        }

        $session = AcademicSession::firstOrCreate(['name' => '2025/2026']);
        $session->makeCurrent();

        $geo = Specialization::where('name', 'Geophysics')->value('id');
        $nuclear = Specialization::where('name', 'Nuclear Physics')->value('id');

        // code, title, units, level, semester, type, specialization_id, is_gst
        $courses = [
            ['GST 111', 'Communication in English I (sample)', 2, 100, 1, 'C', null, true],
            ['PHY 101', 'General Physics I (sample)', 3, 100, 1, 'C', null, false],
            ['PHY 107', 'Practical Physics I (sample)', 1, 100, 1, 'C', null, false],
            ['MTH 101', 'Elementary Mathematics I (sample)', 3, 100, 1, 'C', null, false],
            ['CSC 101', 'Introduction to Computing (sample)', 2, 100, 1, 'E', null, false],
            ['PHY 201', 'Mechanics and Properties of Matter (sample)', 3, 200, 1, 'C', null, false],
            ['PHY 203', 'Electricity and Magnetism (sample)', 3, 200, 1, 'C', null, false],
            ['STA 201', 'Statistics for Physical Sciences (sample)', 2, 200, 1, 'E', null, false],
            ['PHY 301', 'Quantum Mechanics I (sample)', 3, 300, 1, 'C', null, false],
            ['PHY 401', 'Statistical Physics (sample)', 3, 400, 1, 'C', null, false],
            ['PHY 411', 'Seismology (sample)', 3, 400, 1, 'E', $geo, false],
            ['PHY 421', 'Nuclear Reactor Physics (sample)', 3, 400, 1, 'E', $nuclear, false],
            ['PHY 431', 'History of Physics (sample)', 2, 400, 1, 'E', null, false],
        ];
        foreach ($courses as [$code, $title, $units, $level, $semester, $type, $area, $gst]) {
            Course::updateOrCreate(['code' => $code], [
                'title' => $title, 'units' => $units, 'level' => $level, 'semester' => $semester,
                'type' => $type, 'specialization_id' => $area, 'is_gst' => $gst, 'is_active' => true,
            ]);
        }

        $password = config('portal.seed_password');
        $students = [
            ['student@physics.test', 'DEMO/PHY/001', 'Demo Student', 'female', StudentProfile::ENTRY_REGULAR, 100],
            ['student2@physics.test', 'DEMO/PHY/002', 'Demo Student Two', 'male', StudentProfile::ENTRY_REGULAR, 100],
            ['de@physics.test', 'DEMO/PHY/003', 'Demo Direct Entry', 'male', StudentProfile::ENTRY_DIRECT, 200],
            ['final@physics.test', 'DEMO/PHY/004', 'Demo Final Year', 'female', StudentProfile::ENTRY_REGULAR, 400],
        ];
        foreach ($students as [$email, $matric, $name, $gender, $mode, $level]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name, 'identifier' => $matric, 'role' => User::ROLE_STUDENT, 'password' => $password, 'is_active' => true,
            ]);
            StudentProfile::updateOrCreate(['user_id' => $user->id], [
                'entry_mode' => $mode,
                'entry_level' => $mode === StudentProfile::ENTRY_DIRECT ? 200 : ($level === 100 ? 100 : 100),
                'entry_session_id' => $session->id,
                'jamb_reg_no' => '2025'.substr($matric, -3).'XX',
                'gender' => $gender,
                'current_level' => $level,
                'status' => 'active',
            ]);
        }
    }
}
