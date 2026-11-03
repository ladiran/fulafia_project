<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\StaffMember;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A lecturer edits their own public profile (the admin creates and links it). */
class StaffProfileController extends Controller
{
    public function edit()
    {
        return view('portal.staff-profile', ['member' => $this->member()]);
    }

    public function update(Request $request, MediaService $media): RedirectResponse
    {
        $member = $this->member();
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:150'],
            'research_interests' => ['nullable', 'string', 'max:1000'],
            'bio' => ['nullable', 'string', 'max:10000'],
            'publications' => ['nullable', 'string', 'max:20000'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        unset($data['photo']);

        if ($request->hasFile('photo')) {
            $data['photo_media_id'] = $media->replace($member->photo, $request->file('photo'), $request->user()->id)->id;
        }
        $member->update($data);
        AuditLog::record('staff_profile.self_updated', ['id' => $member->id]);

        return back()->with('status', 'Your public profile has been updated.');
    }

    private function member(): StaffMember
    {
        return StaffMember::where('user_id', auth()->id())->first()
            ?? abort(404, 'No public profile is linked to your account yet. Ask the department to create one.');
    }
}
