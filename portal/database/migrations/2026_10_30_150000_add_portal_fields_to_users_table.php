<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Matric number for students, staff ID for staff; null for admin-only accounts.
            $table->string('identifier')->nullable()->unique()->after('email');
            $table->string('role')->default('student')->after('identifier');
            // Fine-grained permissions for appointed admins (role = admin).
            $table->json('permissions')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['identifier', 'role', 'permissions', 'is_active']);
        });
    }
};
