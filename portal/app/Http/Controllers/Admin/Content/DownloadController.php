<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Download;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DownloadController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function index(Request $request)
    {
        return view('admin.content.downloads', [
            'items' => Download::with('media')->orderByDesc('id')->get(),
            'item' => $request->filled('edit') ? Download::findOrFail($request->integer('edit')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->data($request, fileRequired: true);
        $file = $data['file'];
        unset($data['file']);

        $download = Download::create($data + ['media_id' => $this->media->store($file, $request->user()->id)->id]);
        AuditLog::record('download.created', ['title' => $download->title]);

        return redirect()->route('admin.downloads.index')->with('status', 'File added.');
    }

    public function update(Request $request, Download $download): RedirectResponse
    {
        $data = $this->data($request, fileRequired: false);
        $file = $data['file'] ?? null;
        unset($data['file']);

        if ($file) {
            $data['media_id'] = $this->media->replace($download->media, $file, $request->user()->id)->id;
        }
        $download->update($data);
        AuditLog::record('download.updated', ['title' => $download->title]);

        return redirect()->route('admin.downloads.index')->with('status', 'Saved.');
    }

    public function destroy(Download $download): RedirectResponse
    {
        AuditLog::record('download.deleted', ['title' => $download->title]);
        $media = $download->media;
        $download->delete();
        $this->media->delete($media);

        return redirect()->route('admin.downloads.index')->with('status', 'File deleted.');
    }

    private function data(Request $request, bool $fileRequired): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['required', Rule::in(array_keys(Download::CATEGORIES))],
            'file' => [$fileRequired ? 'required' : 'nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'max:10240'],
        ]);

        return $data + ['is_published' => $request->boolean('is_published')];
    }
}
