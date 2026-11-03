<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\Course;
use App\Models\Download;
use App\Models\Event;
use App\Models\GalleryItem;
use App\Models\MediaFile;
use App\Models\Page;
use App\Models\Post;
use App\Models\StaffMember;
use App\Models\User;
use App\Notifications\PortalMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** The public face of the department. Everything here is read-only except the contact form. */
class SiteController extends Controller
{
    public function home()
    {
        return view('public.home', [
            'news' => Post::live()->with('image')->orderByDesc('is_pinned')->orderByDesc('published_at')->limit(3)->get(),
            'events' => Event::where('is_published', true)->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at')->limit(3)->get(),
            'hod' => Page::published()->where('slug', 'hod-message')->first(),
        ]);
    }

    public function page(string $slug)
    {
        return view('public.page', ['page' => Page::published()->where('slug', $slug)->firstOrFail()]);
    }

    public function news(Request $request)
    {
        $type = $request->query('type');

        return view('public.news', [
            'posts' => Post::live()->with('image')
                ->when(in_array($type, array_keys(Post::TYPES), true), fn ($q) => $q->where('type', $type))
                ->orderByDesc('is_pinned')->orderByDesc('published_at')->paginate(9)->withQueryString(),
            'type' => $type,
        ]);
    }

    public function post(string $slug)
    {
        return view('public.post', ['post' => Post::live()->with('image')->where('slug', $slug)->firstOrFail()]);
    }

    public function events()
    {
        return view('public.events', [
            'upcoming' => Event::where('is_published', true)->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at')->get(),
            'past' => Event::where('is_published', true)->where('starts_at', '<', now()->startOfDay())->orderByDesc('starts_at')->limit(10)->get(),
        ]);
    }

    public function staff()
    {
        $members = StaffMember::with(['photo', 'user'])->where('is_published', true)->get();

        return view('public.staff', ['groups' => StaffMember::directory($members)]);
    }

    public function staffMember(StaffMember $member)
    {
        abort_unless($member->is_published, 404);

        return view('public.staff-member', ['member' => $member->load(['photo', 'user'])]);
    }

    /** Course catalogue, generated from the live course list. */
    public function courses()
    {
        $courses = Course::with('specialization')->where('is_active', true)->orderBy('level')->orderBy('semester')->orderBy('code')->get();

        return view('public.courses', ['byLevel' => $courses->groupBy('level')]);
    }

    public function gallery()
    {
        return view('public.gallery', ['items' => GalleryItem::with('media')->where('is_published', true)->orderBy('sort_order')->latest()->get()]);
    }

    public function downloads()
    {
        return view('public.downloads', [
            'groups' => Download::with('media')->where('is_published', true)->orderBy('title')->get()->groupBy('category'),
        ]);
    }

    public function download(Download $download)
    {
        abort_unless($download->is_published, 404);
        $download->increment('download_count');

        return $this->serve($download->media, attachment: true);
    }

    /** Serves uploaded CMS files from private storage. */
    public function media(MediaFile $media)
    {
        return $this->serve($media, attachment: ! $media->isImage());
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $results = null;

        if (mb_strlen($q) >= 2) {
            $results = [
                'pages' => Page::published()->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like))->limit(10)->get(),
                'news' => Post::live()->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('body', 'like', $like))->limit(10)->get(),
                'events' => Event::where('is_published', true)->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like))->limit(10)->get(),
                'staff' => StaffMember::where('is_published', true)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('research_interests', 'like', $like))->limit(10)->get(),
                'courses' => Course::where('is_active', true)->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('title', 'like', $like))->limit(15)->get(),
            ];
        }

        return view('public.search', compact('q', 'results'));
    }

    public function contact()
    {
        return view('public.contact');
    }

    public function sendContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
            'website' => ['nullable', 'max:0'], // honeypot: real visitors leave it empty
        ]);

        ContactMessage::create(collect($data)->only(['name', 'email', 'message'])->all());
        User::whereIn('role', [User::ROLE_PRINCIPAL_ADMIN, User::ROLE_SECONDARY_ADMIN])->where('is_active', true)->get()
            ->each(fn ($u) => $u->notify(new PortalMessage('Contact form message', "New message from {$data['name']} on the public site.")));

        return back()->with('status', 'Thank you. Your message has been sent to the department.');
    }

    private function serve(MediaFile $media, bool $attachment)
    {
        abort_unless(Storage::disk('local')->exists($media->path), 404);

        return Storage::disk('local')->response($media->path, $media->original_name, [
            'Content-Type' => $media->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ], $attachment ? 'attachment' : 'inline');
    }
}
