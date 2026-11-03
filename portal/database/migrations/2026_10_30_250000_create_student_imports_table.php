<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per CSV upload. The file and the credentials file live in private storage and are
        // deleted after use (see imports:purge); only counts and the error list stay here.
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->string('status')->default('previewed'); // previewed | done
            $table->foreignId('default_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->json('errors')->nullable();
            $table->string('file_path')->nullable();
            $table->string('credentials_path')->nullable();
            $table->timestamp('credentials_downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
    }
};
