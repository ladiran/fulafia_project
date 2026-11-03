<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_level_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('from_level');
            $table->unsignedSmallInteger('to_level');
            $table->string('outcome'); // promoted | repeat | graduated
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('promotion_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft'); // draft | proposed | approved
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('from_level');
            $table->string('decision'); // promote | repeat
            $table->decimal('cgpa', 4, 2)->nullable();
            $table->unsignedSmallInteger('carry_overs')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['promotion_batch_id', 'student_profile_id']);
        });

        Schema::create('graduation_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('entry_mode')->unique();
            $table->unsignedSmallInteger('min_credits_registered');
            $table->unsignedSmallInteger('min_credits_passed');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Electives the HoD has waived for a student. Never deleted; revoking stamps revoked_at.
        Schema::create('course_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->foreignId('waived_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_profile_id', 'course_id']);
        });

        Schema::create('graduation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('proposed'); // proposed | approved | rejected
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('graduation_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('graduation_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['graduation_batch_id', 'student_profile_id']);
        });

        // Permanent register. Append-only (guarded in the models).
        Schema::create('graduates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('graduation_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('matric');
            $table->string('name');
            $table->string('gender');
            $table->string('entry_mode');
            $table->string('entry_session')->nullable();
            $table->string('graduation_session');
            $table->unsignedSmallInteger('graduation_year');
            $table->string('degree');
            $table->string('specialization')->nullable();
            $table->decimal('cgpa', 4, 2);
            $table->unsignedSmallInteger('credits_registered');
            $table->unsignedSmallInteger('credits_passed');
            $table->string('class_of_degree');
            $table->string('verification_code')->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
        });

        Schema::create('graduate_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('graduate_id')->constrained()->restrictOnDelete();
            $table->string('session');
            $table->unsignedTinyInteger('semester');
            $table->string('course_code');
            $table->string('course_title');
            $table->unsignedTinyInteger('units');
            $table->char('course_type', 1);
            $table->decimal('ca', 5, 2)->nullable();
            $table->decimal('exam', 5, 2)->nullable();
            $table->decimal('total', 5, 2)->nullable();
            $table->string('grade', 2)->nullable();
            $table->unsignedTinyInteger('grade_point')->nullable();
            $table->string('flag')->nullable();
            $table->boolean('waived')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['graduate_results', 'graduates', 'graduation_batch_items', 'graduation_batches', 'course_waivers',
            'graduation_requirements', 'promotion_items', 'promotion_batches', 'student_level_history'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
