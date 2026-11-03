<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** Development accounts only. The password comes from SEED_PASSWORD in .env (see config/portal.php). */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('portal.seed_password');

        $accounts = [
            ['Head of Department', 'hod@physics.test', 'STAFF-HOD', User::ROLE_PRINCIPAL_ADMIN],
            ['Examination Officer', 'eo@physics.test', 'STAFF-EO', User::ROLE_SECONDARY_ADMIN],
            ['Dr. Lecturer One', 'lecturer1@physics.test', 'STAFF-001', User::ROLE_LECTURER],
            ['Dr. Lecturer Two', 'lecturer2@physics.test', 'STAFF-002', User::ROLE_LECTURER],
            ['Dr. Lecturer Three', 'lecturer3@physics.test', 'STAFF-003', User::ROLE_LECTURER],
        ];

        foreach ($accounts as [$name, $email, $identifier, $role]) {
            User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'identifier' => $identifier, 'role' => $role, 'password' => $password, 'is_active' => true],
            );
        }

        $this->call(AcademicSeeder::class);
        $this->call(SiteContentSeeder::class);
    }
}
