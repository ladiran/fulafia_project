<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->unique()->constrained()->restrictOnDelete();
            $table->string('previous_institution')->nullable();
            $table->string('previous_programme')->nullable();
            $table->string('status')->default('draft'); // draft | submitted | approved
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // The previous institution's transcripts and letters. Private: served only to the EO and HoD.
        Schema::create('transfer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->timestamps();
        });

        // A course the student does not have to take again because of work done elsewhere.
        Schema::create('course_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('previous_code')->nullable();
            $table->string('previous_title')->nullable();
            $table->string('previous_grade', 10)->nullable();
            // Whether the course's credit units count toward the credits passed for graduation.
            $table->boolean('counts_as_credit')->default(false);
            $table->timestamps();

            $table->unique(['student_profile_id', 'course_id']);
        });

        Schema::table('graduate_results', function (Blueprint $table) {
            $table->boolean('exempted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('graduate_results', function (Blueprint $table) {
            $table->dropColumn('exempted');
        });
        Schema::dropIfExists('course_exemptions');
        Schema::dropIfExists('transfer_documents');
        Schema::dropIfExists('transfer_evaluations');
    }
};
