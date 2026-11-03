<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.content.pages', [
            'items' => Page::orderBy('menu_group')->orderBy('sort_order')->orderBy('title')->get(),
            'item' => $request->filled('edit') ? Page::findOrFail($request->integer('edit')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $page = Page::create($this->data($request) + ['updated_by' => $request->user()->id]);
        AuditLog::record('page.created', ['slug' => $page->slug]);

        return redirect()->route('admin.pages.index')->with('status', "Page \"{$page->title}\" created.");
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $page->update($this->data($request, $page) + ['updated_by' => $request->user()->id]);
        AuditLog::record('page.updated', ['slug' => $page->slug]);

        return redirect()->route('admin.pages.index')->with('status', 'Page saved.');
    }

    public function destroy(Page $page): RedirectResponse
    {
        AuditLog::record('page.deleted', ['slug' => $page->slug]);
        $page->delete();

        return redirect()->route('admin.pages.index')->with('status', 'Page deleted.');
    }

    private function data(Request $request, ?Page $page = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'body' => ['required', 'string', 'max:100000'],
            'menu_group' => ['nullable', Rule::in(array_keys(Page::MENU_GROUPS))],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
        ]);

        return $data + [
            'sort_order' => $data['sort_order'] ?? 100,
            'show_in_menu' => $request->boolean('show_in_menu'),
            'is_published' => $request->boolean('is_published'),
        ];
    }
}
