<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffMemberController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function index(Request $request)
    {
        return view('admin.content.staff', [
            'items' => StaffMember::ordered(StaffMember::with('user')->get()),
            'item' => $request->filled('edit') ? StaffMember::findOrFail($request->integer('edit')) : null,
            'lecturers' => User::where('role', User::ROLE_LECTURER)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->data($request);
        if ($request->hasFile('photo')) {
            $data['photo_media_id'] = $this->media->store($request->file('photo'), $request->user()->id)->id;
        }
        $member = StaffMember::create($data);
        AuditLog::record('staff_profile.created', ['name' => $member->name]);

        return redirect()->route('admin.staff.index')->with('status', "{$member->fullName()} added to the directory.");
    }

    public function update(Request $request, StaffMember $member): RedirectResponse
    {
        $data = $this->data($request, $member);
        if ($request->hasFile('photo')) {
            $data['photo_media_id'] = $this->media->replace($member->photo, $request->file('photo'), $request->user()->id)->id;
        }
        $member->update($data);
        AuditLog::record('staff_profile.updated', ['name' => $member->name]);

        return redirect()->route('admin.staff.index')->with('status', 'Profile saved.');
    }

    public function destroy(StaffMember $member): RedirectResponse
    {
        AuditLog::record('staff_profile.deleted', ['name' => $member->name]);
        $photo = $member->photo;
        $member->delete();
        $this->media->delete($photo);

        return redirect()->route('admin.staff.index')->with('status', 'Profile removed from the directory.');
    }

    private function data(Request $request, ?StaffMember $member = null): array
    {
        $data = $request->validate([
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('role', User::ROLE_LECTURER), Rule::unique('staff_members', 'user_id')->ignore($member?->id)],
            'category' => ['required', Rule::in(array_keys(StaffMember::CATEGORIES))],
            'cadre' => ['nullable', Rule::in(StaffMember::CADRES), 'required_if:category,academic'],
            'title' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'research_interests' => ['nullable', 'string', 'max:1000'],
            'bio' => ['nullable', 'string', 'max:10000'],
            'publications' => ['nullable', 'string', 'max:20000'],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        unset($data['photo']);
        // Cadres belong to academic staff only.
        if ($data['category'] !== 'academic') {
            $data['cadre'] = null;
        }

        return $data + ['sort_order' => $data['sort_order'] ?? 100, 'is_published' => $request->boolean('is_published')];
    }
}
