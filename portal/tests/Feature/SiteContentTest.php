<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Course;
use App\Models\Download;
use App\Models\Event;
use App\Models\MediaFile;
use App\Models\Page;
use App\Models\Post;
use App\Models\SiteSetting;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteContentTest extends TestCase
{
    use RefreshDatabase;

    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::flush();
        Storage::fake('local');
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
    }

    private function page(array $extra = []): Page
    {
        return Page::create($extra + ['slug' => 'about', 'title' => 'About us', 'body' => 'Hello **world**', 'is_published' => true]);
    }

    private function makePost(array $extra = []): Post
    {
        return Post::create($extra + [
            'type' => 'news', 'title' => 'First post', 'slug' => 'first-post', 'body' => 'Body text',
            'is_published' => true, 'published_at' => now()->subHour(),
        ]);
    }

    // ---- Public pages ----

    public function test_every_public_page_renders(): void
    {
        $this->page();
        $this->makePost();

        foreach (['/', '/news', '/events', '/staff-directory', '/courses', '/gallery', '/downloads', '/contact', '/verify', '/search?q=ab', '/p/about', '/news/first-post'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_unpublished_pages_and_posts_stay_hidden(): void
    {
        $this->page(['slug' => 'secret', 'title' => 'Secret page', 'is_published' => false]);
        $this->makePost(['slug' => 'draft', 'title' => 'Draft post', 'is_published' => false]);
        $this->makePost(['slug' => 'later', 'title' => 'Scheduled post', 'published_at' => now()->addDay()]);

        $this->get('/p/secret')->assertNotFound();
        $this->get('/news/draft')->assertNotFound();
        $this->get('/news/later')->assertNotFound();
        $this->get('/news')->assertDontSee('Draft post')->assertDontSee('Scheduled post');
    }

    public function test_pinned_posts_come_first_and_the_type_filter_works(): void
    {
        $this->makePost(['slug' => 'old-pinned', 'title' => 'Pinned notice', 'is_pinned' => true, 'published_at' => now()->subYear()]);
        $this->makePost(['slug' => 'fresh', 'title' => 'Fresh item', 'published_at' => now()->subMinute()]);
        $this->makePost(['slug' => 'ann', 'title' => 'An announcement', 'type' => 'announcement']);

        $this->get('/news')->assertSeeInOrder(['Pinned notice', 'Fresh item']);
        $this->get('/news?type=announcement')->assertSee('An announcement')->assertDontSee('Fresh item');
    }

    public function test_markdown_is_sanitised(): void
    {
        $this->page(['slug' => 'xss', 'body' => "<script>alert('x')</script>\n\n[click](javascript:alert(1))\n\n**bold**"]);

        $html = $this->get('/p/xss')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_menu_pages_appear_in_the_navigation(): void
    {
        $this->page(['slug' => 'vision', 'title' => 'Our vision', 'menu_group' => 'about', 'show_in_menu' => true]);
        $this->page(['slug' => 'hidden-menu', 'title' => 'Not in menu', 'show_in_menu' => false]);

        $this->get('/')->assertSee('Our vision')->assertDontSee('Not in menu');
    }

    public function test_events_split_into_upcoming_and_past(): void
    {
        Event::create(['title' => 'Physics seminar', 'starts_at' => now()->addWeek(), 'is_published' => true]);
        Event::create(['title' => 'Old workshop', 'starts_at' => now()->subWeek(), 'is_published' => true]);
        Event::create(['title' => 'Hidden event', 'starts_at' => now()->addWeek(), 'is_published' => false]);

        $this->get('/events')->assertSee('Physics seminar')->assertSee('Old workshop')->assertDontSee('Hidden event');
        $this->get('/')->assertSee('Physics seminar')->assertDontSee('Old workshop');
    }

    public function test_the_course_catalogue_lists_active_courses(): void
    {
        Course::create(['code' => 'PHY 101', 'title' => 'General Physics', 'units' => 3, 'level' => 100, 'semester' => 1, 'type' => 'C']);
        Course::create(['code' => 'PHY 999', 'title' => 'Retired course', 'units' => 3, 'level' => 100, 'semester' => 1, 'type' => 'C', 'is_active' => false]);

        $this->get('/courses')->assertSee('General Physics')->assertDontSee('Retired course');
    }

    public function test_search_finds_visible_content_only(): void
    {
        $this->page(['slug' => 'optics', 'title' => 'Optics laboratory']);
        $this->page(['slug' => 'optics-draft', 'title' => 'Optics draft', 'is_published' => false]);
        StaffMember::create(['name' => 'Ada Optica', 'is_published' => true]);
        StaffMember::create(['name' => 'Hidden Optica', 'is_published' => false]);

        $this->get('/search?q=optic')->assertSee('Optics laboratory')->assertSee('Ada Optica')
            ->assertDontSee('Optics draft')->assertDontSee('Hidden Optica');
        $this->get('/search?q=o')->assertSee('at least two characters');
    }

    // ---- Contact form ----

    public function test_contact_form_stores_a_message_and_notifies_the_hod_and_eo(): void
    {
        $this->post('/contact', ['name' => 'Visitor', 'email' => 'v@example.com', 'message' => 'Hello'])->assertSessionHasNoErrors();

        $this->assertSame(1, ContactMessage::count());
        $this->assertSame(1, $this->hod->notifications()->count());
        $this->assertSame(1, $this->eo->notifications()->count());
    }

    public function test_contact_form_rejects_bots_and_bad_input(): void
    {
        $this->post('/contact', ['name' => 'Bot', 'email' => 'b@example.com', 'message' => 'spam', 'website' => 'http://spam'])->assertSessionHasErrors('website');
        $this->post('/contact', ['name' => '', 'email' => 'not-an-email', 'message' => ''])->assertSessionHasErrors(['name', 'email', 'message']);
        $this->assertSame(0, ContactMessage::count());
    }

    // ---- Permissions ----

    public function test_only_people_with_manage_content_reach_the_cms(): void
    {
        $writer = User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_content']]);
        $other = User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_courses']]);
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        foreach (['/admin/content', '/admin/content/pages', '/admin/content/posts', '/admin/content/events', '/admin/content/staff', '/admin/content/downloads', '/admin/content/gallery', '/admin/content/messages'] as $url) {
            $this->actingAs($writer)->get($url)->assertOk();
            $this->actingAs($this->hod)->get($url)->assertOk();
            $this->actingAs($this->eo)->get($url)->assertOk();
            $this->actingAs($other)->get($url)->assertForbidden();
            $this->actingAs($lecturer)->get($url)->assertForbidden();
            $this->actingAs($student)->get($url)->assertForbidden();
        }
        auth()->logout();
        $this->post('/admin/content/pages', [])->assertRedirect('/login');
    }

    // ---- Pages ----

    public function test_pages_can_be_created_edited_and_deleted_with_unique_clean_slugs(): void
    {
        $data = ['title' => 'Facilities', 'slug' => 'facilities', 'body' => '## Labs', 'is_published' => '1'];

        $this->actingAs($this->eo)->post('/admin/content/pages', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pages', ['slug' => 'facilities']);

        $this->actingAs($this->eo)->post('/admin/content/pages', $data)->assertSessionHasErrors('slug');
        $this->actingAs($this->eo)->post('/admin/content/pages', ['slug' => 'Bad Slug!'] + $data)->assertSessionHasErrors('slug');

        $page = Page::where('slug', 'facilities')->firstOrFail();
        $this->actingAs($this->eo)->put("/admin/content/pages/{$page->id}", ['title' => 'Our facilities'] + $data)->assertSessionHasNoErrors();
        $this->assertSame('Our facilities', $page->fresh()->title);

        $this->actingAs($this->eo)->delete("/admin/content/pages/{$page->id}");
        $this->assertDatabaseMissing('pages', ['slug' => 'facilities']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'page.deleted']);
    }

    // ---- Posts and media ----

    public function test_a_post_with_an_image_is_served_safely_and_cleaned_up_on_delete(): void
    {
        $this->actingAs($this->eo)->post('/admin/content/posts', [
            'type' => 'news', 'title' => 'Open day', 'body' => 'Join us', 'is_published' => '1',
            'image' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ])->assertSessionHasNoErrors();

        $post = Post::firstOrFail();
        $this->assertSame('open-day', $post->slug);
        Storage::disk('local')->assertExists($post->image->path);

        $response = $this->get($post->image->url())->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $path = $post->image->path;
        $this->actingAs($this->eo)->delete("/admin/content/posts/{$post->id}");
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, MediaFile::count());
    }

    public function test_a_post_without_a_date_goes_live_now_and_a_future_date_schedules_it(): void
    {
        $this->actingAs($this->eo)->post('/admin/content/posts', ['type' => 'news', 'title' => 'Now post', 'body' => 'x', 'is_published' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->eo)->post('/admin/content/posts', ['type' => 'news', 'title' => 'Later post', 'body' => 'x', 'is_published' => '1', 'published_at' => now()->addDays(3)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();

        $this->assertNotNull(Post::where('slug', 'now-post')->value('published_at'));
        $this->get('/news/now-post')->assertOk();
        $this->get('/news/later-post')->assertNotFound();
        $this->actingAs($this->eo)->get('/admin/content/posts')->assertOk()->assertSee('SCHEDULED')->assertSee('LIVE');
    }

    public function test_slugs_for_posts_with_the_same_title_stay_unique(): void
    {
        foreach (range(1, 3) as $i) {
            $this->actingAs($this->eo)->post('/admin/content/posts', ['type' => 'news', 'title' => 'Same title', 'body' => 'x', 'is_published' => '1']);
        }

        $this->assertEqualsCanonicalizing(['same-title', 'same-title-2', 'same-title-3'], Post::pluck('slug')->all());
    }

    public function test_dangerous_uploads_are_refused(): void
    {
        $post = ['type' => 'news', 'title' => 'T', 'body' => 'x'];

        $this->actingAs($this->eo)->post('/admin/content/posts', $post + ['image' => UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('image');
        $this->actingAs($this->eo)->post('/admin/content/posts', $post + ['image' => UploadedFile::fake()->create('evil.php', 5, 'image/jpeg')])->assertSessionHasErrors('image');
        $this->actingAs($this->eo)->post('/admin/content/downloads', ['title' => 'x', 'category' => 'other', 'file' => UploadedFile::fake()->create('run.exe', 5)])->assertSessionHasErrors('file');
        $this->assertSame(0, MediaFile::count());
    }

    // ---- Downloads ----

    public function test_downloads_are_public_counted_and_sent_as_attachments(): void
    {
        $this->actingAs($this->eo)->post('/admin/content/downloads', [
            'title' => 'Department handbook', 'category' => 'handbook', 'is_published' => '1',
            'file' => UploadedFile::fake()->create('handbook.pdf', 200, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $download = Download::firstOrFail();

        $this->get('/downloads')->assertSee('Department handbook');
        $response = $this->get("/downloads/{$download->id}/get")->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, $download->fresh()->download_count);

        $download->update(['is_published' => false]);
        $this->get("/downloads/{$download->id}/get")->assertNotFound();
        $this->get('/downloads')->assertDontSee('Department handbook');
    }

    // ---- Staff directory ----

    public function test_staff_profiles_are_public_when_shown_and_hidden_otherwise(): void
    {
        $this->actingAs($this->hod)->post('/admin/content/staff', [
            'category' => 'academic', 'cadre' => 'Professor', 'title' => 'Prof.', 'name' => 'Ngozi Eze', 'position' => 'Professor', 'research_interests' => 'Photonics', 'is_published' => '1',
        ])->assertSessionHasNoErrors();
        $member = StaffMember::firstOrFail();

        $this->get('/staff-directory')->assertSee('Prof. Ngozi Eze');
        $this->get("/staff-directory/{$member->id}")->assertOk()->assertSee('Photonics');

        $member->update(['is_published' => false]);
        $this->get("/staff-directory/{$member->id}")->assertNotFound();
    }

    public function test_a_lecturer_edits_only_their_own_linked_profile(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $other = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $mine = StaffMember::create(['name' => 'Me', 'user_id' => $lecturer->id, 'is_published' => true]);
        StaffMember::create(['name' => 'Someone else', 'user_id' => $other->id, 'is_published' => true]);

        $this->actingAs($lecturer)->post('/staff/profile', ['bio' => 'I teach optics.', 'research_interests' => 'Lasers', 'name' => 'Hacked name', 'position' => 'Hacked'])
            ->assertSessionHasNoErrors();

        $mine->refresh();
        $this->assertSame('I teach optics.', $mine->bio);
        $this->assertSame('Me', $mine->name, 'name and position are controlled by the department');
        $this->assertNull($mine->position);

        $unlinked = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $this->actingAs($unlinked)->get('/staff/profile')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->get('/staff/profile')->assertForbidden();
    }

    public function test_a_lecturer_account_can_only_be_linked_to_one_profile(): void
    {
        $lecturer = User::factory()->create(['role' => User::ROLE_LECTURER]);
        StaffMember::create(['name' => 'First', 'user_id' => $lecturer->id]);

        $this->actingAs($this->hod)->post('/admin/content/staff', ['name' => 'Second', 'user_id' => $lecturer->id])->assertSessionHasErrors('user_id');
    }

    // ---- Gallery and settings ----

    public function test_gallery_accepts_several_photos_and_shows_only_published_ones(): void
    {
        $this->actingAs($this->eo)->post('/admin/content/gallery', [
            'caption' => 'Open day 2026',
            'photos' => [UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'), UploadedFile::fake()->create('b.png', 50, 'image/png')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, \App\Models\GalleryItem::count());
        $this->get('/gallery')->assertSee('Open day 2026');

        \App\Models\GalleryItem::query()->update(['is_published' => false]);
        $this->get('/gallery')->assertDontSee('Open day 2026');
    }

    public function test_site_settings_change_the_public_pages(): void
    {
        $this->actingAs($this->hod)->post('/admin/content/settings', [
            'hero_title' => 'Physics at FULafia', 'hero_text' => 'A new intro.', 'address' => '1 Test Road, Lafia',
            'phone' => '000', 'email' => 'physics@example.com', 'office_hours' => 'Daily',
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertSee('Physics at FULafia')->assertSee('A new intro.');
        $this->get('/contact')->assertSee('1 Test Road, Lafia')->assertSee('physics@example.com');

        $this->actingAs($this->hod)->post('/admin/content/settings', ['hero_title' => '', 'hero_text' => 'x', 'address' => 'y', 'email' => 'nope'])->assertSessionHasErrors(['hero_title', 'email']);
    }

    public function test_contact_messages_can_be_read_and_deleted_by_content_managers(): void
    {
        $message = ContactMessage::create(['name' => 'V', 'email' => 'v@example.com', 'message' => 'Hi']);

        $this->actingAs($this->eo)->post("/admin/content/messages/{$message->id}/read")->assertRedirect();
        $this->assertTrue($message->fresh()->is_read);
        $this->actingAs($this->eo)->delete("/admin/content/messages/{$message->id}");
        $this->assertSame(0, ContactMessage::count());
    }
}
