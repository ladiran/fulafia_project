<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Post;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function index(Request $request)
    {
        return view('admin.content.posts', [
            'items' => Post::orderByDesc('published_at')->orderByDesc('id')->get(),
            'item' => $request->filled('edit') ? Post::findOrFail($request->integer('edit')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->data($request);
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['created_by'] = $request->user()->id;
        if ($request->hasFile('image')) {
            $data['image_media_id'] = $this->media->store($request->file('image'), $request->user()->id)->id;
        }

        $post = Post::create($data);
        AuditLog::record('post.created', ['slug' => $post->slug]);

        return redirect()->route('admin.posts.index')->with('status', "\"{$post->title}\" saved.");
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $data = $this->data($request);
        if ($request->hasFile('image')) {
            $data['image_media_id'] = $this->media->replace($post->image, $request->file('image'), $request->user()->id)->id;
        } elseif ($request->boolean('remove_image') && $post->image) {
            $this->media->delete($post->image);
            $data['image_media_id'] = null;
        }

        $post->update($data);
        AuditLog::record('post.updated', ['slug' => $post->slug]);

        return redirect()->route('admin.posts.index')->with('status', 'Post saved.');
    }

    public function destroy(Post $post): RedirectResponse
    {
        AuditLog::record('post.deleted', ['slug' => $post->slug]);
        $image = $post->image;
        $post->delete();
        $this->media->delete($image);

        return redirect()->route('admin.posts.index')->with('status', 'Post deleted.');
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Post::TYPES))],
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:100000'],
            'published_at' => ['nullable', 'date'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        unset($data['image']);

        // A future date schedules the post; empty means "now".
        $data['published_at'] = ($data['published_at'] ?? null) ?: now();
        $data['is_published'] = $request->boolean('is_published');
        $data['is_pinned'] = $request->boolean('is_pinned');

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        for ($i = 2; Post::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
