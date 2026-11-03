<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GalleryItem;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GalleryController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function index()
    {
        return view('admin.content.gallery', ['items' => GalleryItem::with('media')->orderBy('sort_order')->latest()->get()]);
    }

    /** Upload one or several photos at once; the caption applies to all of them. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:200'],
            'photos' => ['required', 'array', 'max:20'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        foreach ($request->file('photos') as $photo) {
            GalleryItem::create(['caption' => $data['caption'] ?? null, 'media_id' => $this->media->store($photo, $request->user()->id)->id, 'is_published' => true]);
        }
        AuditLog::record('gallery.uploaded', ['count' => count($request->file('photos'))]);

        return back()->with('status', count($request->file('photos')).' photo(s) added.');
    }

    public function update(Request $request, GalleryItem $item): RedirectResponse
    {
        $data = $request->validate(['caption' => ['nullable', 'string', 'max:200'], 'sort_order' => ['nullable', 'integer', 'between:0,1000']]);
        $item->update(['caption' => $data['caption'] ?? null, 'sort_order' => $data['sort_order'] ?? 100, 'is_published' => $request->boolean('is_published')]);

        return back()->with('status', 'Saved.');
    }

    public function destroy(GalleryItem $item): RedirectResponse
    {
        $media = $item->media;
        $item->delete();
        $this->media->delete($media);
        AuditLog::record('gallery.deleted', ['id' => $item->id]);

        return back()->with('status', 'Photo deleted.');
    }
}
