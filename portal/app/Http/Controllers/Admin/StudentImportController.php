<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\StudentImport;
use App\Services\StudentImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk admission from a CSV: upload, check (nothing is written), confirm. Temporary passwords come back
 * once, as a CSV the person who imported can download a single time.
 */
class StudentImportController extends Controller
{
    public function __construct(private StudentImportService $importer) {}

    public function index(Request $request)
    {
        return view('admin.students.import', [
            'sessions' => AcademicSession::orderByDesc('name')->get(),
            'history' => StudentImport::with('user')->latest()->limit(10)->get(),
            'max' => StudentImportService::MAX_ROWS,
        ]);
    }

    public function template()
    {
        $csv = $this->importer->csv(StudentImportService::TEMPLATE_HEADER, [
            ['FUL/PHY/25/001', 'Ada Obi', 'female', 'regular', '', '2025/2026', '2025123456AB', 'ada@example.com', '08012345678', '', ''],
            ['FUL/PHY/25/002', 'Musa Bello', 'male', 'direct_entry', '', '2025/2026', '2025654321CD', '', '', '', ''],
            ['FUL/PHY/25/003', 'Chidi Eze', 'male', 'transfer', '200', '2025/2026', '', '', '', '', ''],
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="student-import-template.csv"',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'default_session_id' => ['nullable', 'exists:academic_sessions,id'],
        ]);

        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $session = ($data['default_session_id'] ?? null) ? AcademicSession::find($data['default_session_id']) : null;

        $parsed = $this->importer->parse(Storage::disk('local')->path($path));
        if ($parsed['error']) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => $parsed['error']]);
        }
        $result = $this->importer->validate($parsed['rows'], $session);

        $import = StudentImport::create([
            'user_id' => $request->user()->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'default_session_id' => $session?->id,
            'total_rows' => count($parsed['rows']),
            'valid_rows' => count($result['valid']),
            'error_rows' => count($result['errors']),
            'errors' => array_slice($result['errors'], 0, 500),
            'file_path' => $path,
        ]);

        return redirect()->route('admin.students.import.show', $import);
    }

    public function show(Request $request, StudentImport $import)
    {
        $this->authorizeImport($request, $import);

        $preview = [];
        if ($import->status === 'previewed' && $import->file_path && Storage::disk('local')->exists($import->file_path)) {
            $parsed = $this->importer->parse(Storage::disk('local')->path($import->file_path));
            $valid = $this->importer->validate($parsed['rows'], $import->defaultSession)['valid'];
            $preview = array_slice($valid, 0, 15, true);
        }

        return view('admin.students.import-show', ['import' => $import, 'preview' => $preview]);
    }

    public function confirm(Request $request, StudentImport $import): RedirectResponse
    {
        $this->authorizeImport($request, $import);
        abort_unless($import->status === 'previewed' && $import->file_path && Storage::disk('local')->exists($import->file_path), 404);

        // Check again: someone may have added a student since the preview.
        $parsed = $this->importer->parse(Storage::disk('local')->path($import->file_path));
        $result = $this->importer->validate($parsed['rows'], $import->defaultSession);

        $credentials = $result['valid'] ? $this->importer->import($result['valid']) : [];

        $credentialsPath = null;
        if ($credentials) {
            $credentialsPath = 'import-credentials/'.bin2hex(random_bytes(16)).'.csv';
            Storage::disk('local')->put($credentialsPath, $this->importer->csv(
                ['matric', 'name', 'temporary_password'],
                array_map(fn ($c) => [$c['matric'], $c['name'], $c['password']], $credentials),
            ));
        }
        Storage::disk('local')->delete($import->file_path);

        $import->update([
            'status' => 'done', 'file_path' => null, 'credentials_path' => $credentialsPath,
            'valid_rows' => count($result['valid']), 'error_rows' => count($result['errors']),
            'created_count' => count($credentials), 'errors' => array_slice($result['errors'], 0, 500),
        ]);
        AuditLog::record('students.imported', ['import_id' => $import->id, 'created' => count($credentials), 'skipped' => count($result['errors'])]);

        return redirect()->route('admin.students.import.show', $import)->with('status', count($credentials).' student account(s) created.');
    }

    /** One-time download: the file is deleted as soon as it has been read. */
    public function credentials(Request $request, StudentImport $import)
    {
        $this->authorizeImport($request, $import);
        abort_unless($import->credentials_path && Storage::disk('local')->exists($import->credentials_path), 404);

        $csv = Storage::disk('local')->get($import->credentials_path);
        Storage::disk('local')->delete($import->credentials_path);
        $import->update(['credentials_path' => null, 'credentials_downloaded_at' => now()]);
        AuditLog::record('students.import_credentials_downloaded', ['import_id' => $import->id]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="temporary-passwords-import-'.$import->id.'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function errors(Request $request, StudentImport $import)
    {
        $this->authorizeImport($request, $import);

        $rows = collect($import->errors ?? [])->map(fn ($e) => [$e['line'], $e['matric'], implode(' ', $e['messages'])]);

        return response($this->importer->csv(['line', 'matric', 'problem'], $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="import-problems-'.$import->id.'.csv"',
        ]);
    }

    /** The person who uploaded it, or the HoD. */
    private function authorizeImport(Request $request, StudentImport $import): void
    {
        abort_unless($import->user_id === $request->user()->id || $request->user()->isPrincipalAdmin(), 403);
    }
}
