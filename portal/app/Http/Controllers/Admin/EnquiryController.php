<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enquiry;
use App\Notifications\PortalMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnquiryController extends Controller
{
    public function index()
    {
        return view('admin.enquiries', ['enquiries' => Enquiry::with('student.user')->orderByRaw("status = 'open' desc")->latest()->get()]);
    }

    public function respond(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $data = $request->validate(['response' => ['required', 'string', 'max:2000']]);

        $enquiry->update(['response' => $data['response'], 'status' => 'closed', 'responded_by' => $request->user()->id, 'responded_at' => now()]);
        AuditLog::record('enquiry.answered', ['enquiry_id' => $enquiry->id]);
        $enquiry->student->user->notify(new PortalMessage('Reply to your enquiry', "The department has replied to \"{$enquiry->subject}\"."));

        return back()->with('status', 'Reply sent.');
    }
}
