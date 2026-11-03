<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NameChangeRequest;
use App\Services\NameChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NameChangeController extends Controller
{
    public function __construct(private NameChangeService $names) {}

    public function index()
    {
        return view('admin.name-changes.index', [
            'requests' => NameChangeRequest::with('student.user')->latest()->get(),
        ]);
    }

    public function show(NameChangeRequest $nameChange)
    {
        return view('admin.name-changes.show', [
            'req' => $nameChange->load('student.user'),
            'isHod' => auth()->user()->isPrincipalAdmin(),
        ]);
    }

    /** Documents are in private storage and only served here, to the EO and HoD. */
    public function document(NameChangeRequest $nameChange, string $type)
    {
        abort_unless(in_array($type, ['marriage', 'newspaper'], true), 404);
        $path = $type === 'marriage' ? $nameChange->marriage_document_path : $nameChange->newspaper_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    /** EO or HoD: confirm the documents, or reject. */
    public function verify(Request $request, NameChangeRequest $nameChange): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:ok,reject'], 'note' => ['nullable', 'string', 'max:200']]);
        $this->names->verify($nameChange, $request->user(), $data['decision'] === 'ok', $data['note'] ?? null);

        return back()->with('status', $data['decision'] === 'ok' ? 'Documents verified. The HoD can now decide.' : 'Request rejected.');
    }

    /** HoD only. */
    public function approve(Request $request, NameChangeRequest $nameChange): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $this->names->approve($nameChange, $request->user());

        return back()->with('status', 'Name change approved.');
    }

    /** HoD only. */
    public function reject(Request $request, NameChangeRequest $nameChange): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);
        $this->names->reject($nameChange, $request->user(), $data['note']);

        return back()->with('status', 'Request rejected.');
    }
}
