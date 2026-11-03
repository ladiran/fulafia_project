<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The department's result sheets have two continuous-assessment columns: CA 1, CA 2, then the exam.
        // "ca" keeps meaning CA 1; total = ca + ca2 + exam.
        Schema::table('results', function (Blueprint $table) {
            $table->decimal('ca2', 5, 2)->nullable()->after('ca');
        });
        Schema::table('graduate_results', function (Blueprint $table) {
            $table->decimal('ca2', 5, 2)->nullable()->after('ca');
        });

        // The level a student was at when they registered. Senate broadsheets are drawn per level and
        // session, and a student's current level moves on after promotion.
        Schema::table('course_registrations', function (Blueprint $table) {
            $table->unsignedSmallInteger('level_at_registration')->nullable()->after('semester');
        });
        DB::statement('UPDATE course_registrations SET level_at_registration = (SELECT current_level FROM student_profiles WHERE student_profiles.id = course_registrations.student_profile_id) WHERE level_at_registration IS NULL');
    }

    public function down(): void
    {
        Schema::table('course_registrations', fn (Blueprint $t) => $t->dropColumn('level_at_registration'));
        Schema::table('graduate_results', fn (Blueprint $t) => $t->dropColumn('ca2'));
        Schema::table('results', fn (Blueprint $t) => $t->dropColumn('ca2'));
    }
};
