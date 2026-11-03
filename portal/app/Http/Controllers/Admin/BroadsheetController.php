<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Services\BroadsheetService;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Senate-format level broadsheets (permission: manage_results). */
class BroadsheetController extends Controller
{
    public function __construct(private BroadsheetService $broadsheets, private PdfService $pdf) {}

    public function index()
    {
        return view('admin.broadsheets', [
            'sessions' => AcademicSession::orderByDesc('name')->get(),
            'current' => AcademicSession::current(),
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'exists:academic_sessions,id'],
            'semester' => ['required', Rule::in([1, 2])],
            'level' => ['required', Rule::in([100, 200, 300, 400])],
        ]);
        $session = AcademicSession::findOrFail($data['session_id']);

        $sheet = $this->broadsheets->build($session, (int) $data['semester'], (int) $data['level']);
        abort_if($sheet['rows'] === [], 404, 'No published results for this level, semester and session yet.');
        AuditLog::record('broadsheet.generated', ['session' => $session->name, 'semester' => $data['semester'], 'level' => $data['level']]);

        // Wide broadsheets (many course columns) go on A3, as the department's printed ones do.
        $paper = $sheet['width'] > 1040 ? 'A3' : 'A4';
        $sheet += ['generated_by' => $request->user()->name, 'generated_at' => now()];

        return response($this->pdf->render('pdf.broadsheet', $sheet, $paper, 'landscape'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug("broadsheet-{$data['level']}L-{$session->name}-sem{$data['semester']}").'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
