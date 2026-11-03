<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Graduate;
use App\Models\GraduateAmendment;
use App\Services\GraduateAmendmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The EO (or HoD) asks for a correction to a graduate's permanent record; only the HoD decides. */
class GraduateCorrectionController extends Controller
{
    public function __construct(private GraduateAmendmentService $amendments) {}

    public function index(Request $request)
    {
        return view('admin.corrections', [
            'pending' => GraduateAmendment::with(['graduate', 'requester', 'result'])->where('status', 'requested')->orderBy('id')->get(),
            'decided' => GraduateAmendment::with(['graduate', 'requester', 'decider', 'result'])->where('status', '!=', 'requested')->orderByDesc('decided_at')->limit(30)->get(),
            'isHod' => $request->user()->isPrincipalAdmin(),
        ]);
    }

    public function store(Request $request, Graduate $graduate): RedirectResponse
    {
        $base = $request->validate([
            'kind' => ['required', 'in:field,result'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        if ($base['kind'] === 'field') {
            $data = $request->validate(['field' => ['required', 'string'], 'value' => ['required']]);
            $this->amendments->requestField($graduate, $data['field'], $data['value'], $base['reason'], $request->user());
        } else {
            $data = $request->validate([
                'graduate_result_id' => ['required', 'integer'],
                'ca' => ['nullable'], 'ca2' => ['nullable'], 'exam' => ['nullable'], 'flag' => ['nullable', 'in:absent,incomplete,withheld'],
            ]);
            $this->amendments->requestResult($graduate, (int) $data['graduate_result_id'], $request->only(['ca', 'ca2', 'exam', 'flag']), $base['reason'], $request->user());
        }

        return back()->with('status', 'Correction requested. It takes effect when the HoD approves it. The original record is kept.');
    }

    /** HoD only. */
    public function approve(Request $request, GraduateAmendment $amendment): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $this->amendments->approve($amendment, $request->user());

        return back()->with('status', 'Correction approved. The record now shows the corrected value; the original is kept in the history.');
    }

    /** HoD only. */
    public function reject(Request $request, GraduateAmendment $amendment): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);
        $this->amendments->reject($amendment, $request->user(), $data['note']);

        return back()->with('status', 'Correction rejected.');
    }
}
