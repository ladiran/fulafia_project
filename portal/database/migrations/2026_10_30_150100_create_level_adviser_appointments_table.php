<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_adviser_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('level'); // 100, 200, 300, 400
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('appointed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();
            // Expiry never removes an adviser; only the HoD can, which sets these.
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['level', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_adviser_appointments');
    }
};
