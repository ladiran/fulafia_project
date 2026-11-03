<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiteSettingController extends Controller
{
    /** Content hub: links to every section plus the settings form. */
    public function index()
    {
        return view('admin.content.index', [
            'keys' => SiteSetting::KEYS,
            'unread' => ContactMessage::where('is_read', false)->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hero_title' => ['required', 'string', 'max:120'],
            'hero_text' => ['required', 'string', 'max:600'],
            'address' => ['required', 'string', 'max:250'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'office_hours' => ['nullable', 'string', 'max:150'],
        ]);

        foreach ($data as $key => $value) {
            SiteSetting::put($key, $value);
        }
        AuditLog::record('site_settings.updated');

        return back()->with('status', 'Site settings saved.');
    }

    public function messages()
    {
        return view('admin.content.messages', ['messages' => ContactMessage::latest()->get()]);
    }

    public function readMessage(ContactMessage $message): RedirectResponse
    {
        $message->update(['is_read' => true]);

        return back();
    }

    public function deleteMessage(ContactMessage $message): RedirectResponse
    {
        $message->delete();

        return back()->with('status', 'Message deleted.');
    }
}
