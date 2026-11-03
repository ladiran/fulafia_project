<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Graduate;
use App\Models\StudentProfile;
use App\Services\PdfService;
use App\Services\TranscriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Departmental transcript PDF for the HoD and EO. It is a working copy for the department,
 * NOT an official transcript, and it says so on every page.
 */
class TranscriptController extends Controller
{
    public function __construct(private TranscriptService $transcripts, private PdfService $pdf) {}

    public function student(Request $request, StudentProfile $student)
    {
        $data = $this->transcripts->forStudent($student);
        AuditLog::record('transcript.generated', ['student_profile_id' => $student->id, 'kind' => $data['kind']]);

        return $this->respond($request, $data);
    }

    public function graduate(Request $request, Graduate $graduate)
    {
        $data = $this->transcripts->forGraduate($graduate);
        AuditLog::record('transcript.generated', ['student_profile_id' => $graduate->student_profile_id, 'kind' => 'graduate']);

        return $this->respond($request, $data);
    }

    private function respond(Request $request, array $data)
    {
        $data['generated_by'] = $request->user()->name;
        $data['generated_at'] = now();

        $filename = 'departmental-transcript-'.Str::slug($data['person']['matric']).'.pdf';
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($this->pdf->render('pdf.transcript', $data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
