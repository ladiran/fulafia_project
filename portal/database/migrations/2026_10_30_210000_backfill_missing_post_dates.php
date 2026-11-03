<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Posts saved before the date default was fixed have no publish date; give them their creation time.
    public function up(): void
    {
        DB::table('posts')->whereNull('published_at')->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void {}
};
