<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uploaded files live on the private disk and are served through a route (no storage symlink needed).
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body'); // Markdown
            $table->string('menu_group')->nullable(); // about | admission | null
            $table->boolean('show_in_menu')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_published')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('news'); // news | announcement
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('summary', 300)->nullable();
            $table->longText('body');
            $table->foreignId('image_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete(); // lets the lecturer edit their own profile
            $table->string('title')->nullable(); // Prof., Dr., Mr., Mrs.
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('photo_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->text('research_interests')->nullable();
            $table->text('bio')->nullable();
            $table->text('publications')->nullable(); // Markdown
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('other'); // handbook | forms | timetables | past_questions | other
            $table->foreignId('media_id')->constrained('media_files')->restrictOnDelete();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });

        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->string('caption')->nullable();
            $table->foreignId('media_id')->constrained('media_files')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['contact_messages', 'site_settings', 'gallery_items', 'downloads', 'staff_members', 'events', 'posts', 'pages', 'media_files'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
