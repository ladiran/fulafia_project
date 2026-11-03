<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The academic calendar of a session. Events with a `key` also control the portal (registration, result upload, semester switch).
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40)->nullable();      // registration_1, registration_2, results_1, results_2, semester_2_start
            $table->string('title', 200);
            $table->string('category', 20)->default('other');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();         // empty for a one-day event
            $table->string('override', 10)->nullable();  // HoD: 'open' or 'closed' regardless of the dates
            $table->string('notes', 300)->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['academic_session_id', 'key']);
            $table->index(['academic_session_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
