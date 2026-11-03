<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per course per session: holds the lecturer and the workflow status of the whole result sheet.
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('lecturer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft'); // draft | submitted | vetted | approved | published
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('vetted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vetted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('return_note')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'academic_session_id']);
        });

        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_registration_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->decimal('ca', 5, 2)->nullable();
            $table->decimal('exam', 5, 2)->nullable();
            $table->decimal('total', 5, 2)->nullable();
            $table->string('grade', 2)->nullable();
            $table->unsignedTinyInteger('grade_point')->nullable();
            $table->string('flag')->nullable(); // absent | incomplete | withheld
            $table->timestamps();

            $table->index('course_offering_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
        Schema::dropIfExists('course_offerings');
    }
};
