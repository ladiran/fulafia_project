<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Class progression is automatic now (see SessionRolloverService), so the manual promotion batches go.
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotion_batches');

        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->timestamp('rolled_over_at')->nullable(); // set once students have been moved on at the end of this session
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('sessions_at_400')->default(0); // sessions already finished at 400 level
            $table->string('withdrawal_reason')->nullable();             // e.g. residency_exceeded
        });

        // Senate-approved extensions of residency at 400 level, recorded by the HoD. Never deleted.
        Schema::create('residency_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('sessions_granted');
            $table->string('senate_reference', 150);
            $table->string('note', 300)->nullable();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residency_extensions');
        Schema::table('student_profiles', fn (Blueprint $t) => $t->dropColumn(['sessions_at_400', 'withdrawal_reason']));
        Schema::table('academic_sessions', fn (Blueprint $t) => $t->dropColumn('rolled_over_at'));
    }
};
