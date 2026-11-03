<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deferments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete(); // the session deferred
            $table->string('reason');
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete(); // always the HoD
            $table->string('status')->default('active'); // active | returned | lapsed | withdrawn
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('name_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('current_name');
            $table->string('new_name');
            $table->string('document_type'); // certificate | affidavit
            $table->string('marriage_document_path');
            $table->string('newspaper_path');
            $table->string('status')->default('submitted'); // submitted | verified | approved | rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // Permanent: every name a student has held, with who approved the change.
        Schema::create('student_name_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('name_change_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('previous_name');
            $table->string('new_name');
            $table->string('reason')->default('marriage');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_date');
            $table->timestamps();
        });

        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('category'); // deferment | name_change | results | other
            $table->string('subject');
            $table->text('message');
            $table->string('status')->default('open'); // open | closed
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['enquiries', 'student_name_history', 'name_change_requests', 'deferments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
