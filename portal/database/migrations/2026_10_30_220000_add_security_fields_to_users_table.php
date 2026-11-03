<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->text('two_factor_secret')->nullable();          // encrypted at rest
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();  // JSON list of password hashes
            $table->unsignedBigInteger('two_factor_last_step')->nullable(); // blocks replaying a code
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'must_change_password', 'password_changed_at', 'two_factor_secret', 'two_factor_confirmed_at',
                'two_factor_recovery_codes', 'two_factor_last_step', 'last_login_at', 'last_login_ip',
            ]);
        });
    }
};
