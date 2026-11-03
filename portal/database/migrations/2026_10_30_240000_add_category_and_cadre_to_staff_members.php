<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->string('category')->default('academic')->after('user_id'); // academic | technical | administrative
            $table->string('cadre')->nullable()->after('category');             // academic staff only
        });
    }

    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropColumn(['category', 'cadre']);
        });
    }
};
