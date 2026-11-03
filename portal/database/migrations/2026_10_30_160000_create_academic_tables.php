<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Areas of specialization: managed by the HoD, deactivated rather than deleted.
        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. 2025/2026
            $table->boolean('is_current')->default(false);
            $table->unsignedTinyInteger('current_semester')->default(1);
            $table->boolean('specialization_window_open')->default(false);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->unsignedTinyInteger('units');
            $table->unsignedSmallInteger('level');
            $table->unsignedTinyInteger('semester');
            $table->char('type', 1); // C = core, E = elective
            // An elective linked to an area is mandatory for students in that area.
            $table->foreignId('specialization_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_gst')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['level', 'semester']);
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('entry_mode'); // regular | direct_entry | transfer
            $table->unsignedSmallInteger('entry_level');
            $table->foreignId('entry_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->string('jamb_reg_no')->nullable();
            $table->string('gender'); // male | female
            $table->string('phone')->nullable();
            $table->unsignedSmallInteger('current_level');
            $table->string('status')->default('active'); // active | deferred | suspended | withdrawn | graduated
            $table->foreignId('specialization_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['current_level', 'status']);
        });

        Schema::create('course_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('semester');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['student_profile_id', 'course_id', 'academic_session_id'], 'registration_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_registrations');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('academic_sessions');
        Schema::dropIfExists('specializations');
    }
};
