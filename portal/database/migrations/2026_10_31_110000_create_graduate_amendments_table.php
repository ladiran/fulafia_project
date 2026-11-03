<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The graduate register is permanent and never edited. A correction is a separate, HoD-approved row
        // that is laid over the original when the record is read. Nothing here is ever deleted.
        Schema::create('graduate_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('graduate_id')->constrained()->restrictOnDelete();
            $table->string('kind'); // field | result
            $table->string('field')->nullable();                                    // for kind = field
            $table->foreignId('graduate_result_id')->nullable()->constrained()->restrictOnDelete(); // for kind = result
            $table->json('old_value')->nullable();   // what the record said just before this took effect
            $table->json('new_value');
            $table->string('reason', 300);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('requested'); // requested | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();

            $table->index(['graduate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduate_amendments');
    }
};
