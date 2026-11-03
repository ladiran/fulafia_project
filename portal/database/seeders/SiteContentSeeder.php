<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\Post;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Starter public-site content. Pages carry short placeholders for the department to replace
 * through Admin > Site content; nothing here is an official statement of the department.
 */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        $todo = "_This section is waiting for the department's approved text. Edit it under Admin > Site content > Pages._";

        $pages = [
            ['about', 'About the department', 'about', 10, "The Department of Physics offers a four-year B.Sc. Physics programme (100 to 400 level) in the Faculty of Physical Sciences, Federal University of Lafia.\n\nStudents choose an area of specialization at the beginning of 400 level.\n\n{$todo}"],
            ['vision-mission', 'Vision and mission', 'about', 20, "## Vision\n\n{$todo}\n\n## Mission\n\n{$todo}"],
            ['hod-message', "Message from the Head of Department", 'about', 30, "{$todo}"],
            ['research', 'Research groups and laboratories', 'about', 40, "{$todo}"],
            ['admission-undergraduate', 'Undergraduate admission', 'admission', 10, "The department admits students in three ways:\n\n- **Regular:** through JAMB, entering at 100 level.\n- **Direct Entry:** through JAMB, entering at 200 level.\n- **Transfer:** from other universities or other departments in the faculty, entering at 100 or 200 level.\n\n{$todo}"],
            ['admission-postgraduate', 'Postgraduate admission', 'admission', 20, "{$todo}"],
            ['programmes', 'Programmes', null, 50, "## B.Sc. Physics\n\nA four-year programme with areas of specialization at 400 level:\n\n- Atmospheric Physics\n- Quantum Information Theory\n- Geophysics\n- Photonics\n- Material Physics\n- Nuclear Physics\n- Radiation and Biophysics\n\nSee the [course catalogue](/courses) for the courses at each level."],
            ['alumni', 'Alumni', null, 60, "Graduates of the department keep access to their statement of result through the portal.\n\nEmployers and institutions can [verify a graduate](/verify) with the code printed on the statement.\n\n{$todo}"],
        ];

        foreach ($pages as [$slug, $title, $group, $order, $body]) {
            Page::firstOrCreate(['slug' => $slug], [
                'title' => $title, 'body' => $body, 'menu_group' => $group, 'sort_order' => $order,
                'show_in_menu' => true, 'is_published' => true,
            ]);
        }

        Post::firstOrCreate(['slug' => 'welcome-to-the-new-departmental-portal'], [
            'type' => 'announcement',
            'title' => 'Welcome to the new departmental portal',
            'summary' => 'Students and staff can now log in to register courses, view results and more.',
            'body' => "The Department of Physics now has its own portal.\n\n- Students register courses and view published results.\n- Level Advisers review registrations.\n- Staff upload scores and keep their profiles up to date.\n\nQuestions? Use the contact page or the enquiries form in your portal.",
            'is_published' => true, 'is_pinned' => true, 'published_at' => now(),
        ]);

        // Sample directory entries so the staff page is not empty. Replace with the real staff list.
        $hod = User::where('role', User::ROLE_PRINCIPAL_ADMIN)->first();
        if ($hod) {
            StaffMember::firstOrCreate(['user_id' => $hod->id], [
                'category' => 'academic', 'cadre' => 'Professor', 'title' => 'Prof.', 'name' => 'Head of Department (sample)',
                'is_published' => true,
            ]);
        }
        User::where('role', User::ROLE_LECTURER)->orderBy('id')->get()->each(function (User $u, int $i) {
            StaffMember::firstOrCreate(['user_id' => $u->id], [
                'category' => 'academic', 'cadre' => 'Lecturer I', 'title' => 'Dr.', 'name' => preg_replace('/^Dr\.\s*/', '', $u->name),
                'sort_order' => 100 + $i, 'is_published' => true,
            ]);
        });
    }
}
