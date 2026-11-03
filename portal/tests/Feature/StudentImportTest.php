<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Specialization;
use App\Models\StudentImport;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\StudentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'matric,name,gender,entry_mode,entry_level,session,jamb_reg_no,email,phone,current_level,specialization';

    private AcademicSession $session;
    private User $hod;
    private User $eo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true, 'current_semester' => 1]);
        $this->hod = User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
        $this->eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
    }

    private function csv(array $lines, string $header = self::HEADER): string
    {
        return $header."\n".implode("\n", $lines)."\n";
    }

    private function upload(string $content, ?User $by = null, ?int $sessionId = null)
    {
        return $this->actingAs($by ?? $this->eo)->post('/admin/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $content),
            'default_session_id' => $sessionId ?? $this->session->id,
        ]);
    }

    private function good(string $matric = 'FUL/PHY/25/001', string $extra = ''): string
    {
        return "{$matric},Ada Obi,female,regular,,2025/2026,2025123456AB,{$extra}";
    }

    private function validate(array $rows, ?AcademicSession $default = null): array
    {
        $svc = app(StudentImportService::class);
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $this->csv($rows));
        $parsed = $svc->parse($path);
        unlink($path);

        return $svc->validate($parsed['rows'], $default ?? $this->session);
    }

    // ---- Access ----

    public function test_only_student_managers_can_import(): void
    {
        $grant = User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students']]);
        $noGrant = User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_courses']]);

        foreach ([$this->hod, $this->eo, $grant] as $ok) {
            $this->actingAs($ok)->get('/admin/students/import')->assertOk();
            $this->actingAs($ok)->get('/admin/students/import/template')->assertOk();
        }
        foreach ([$noGrant, User::factory()->create(['role' => User::ROLE_LECTURER]), User::factory()->create(['role' => User::ROLE_STUDENT])] as $no) {
            $this->actingAs($no)->get('/admin/students/import')->assertForbidden();
            $this->actingAs($no)->post('/admin/students/import', [])->assertForbidden();
        }
    }

    public function test_the_template_has_the_expected_columns(): void
    {
        $response = $this->actingAs($this->eo)->get('/admin/students/import/template')->assertOk();

        $this->assertStringStartsWith(self::HEADER, $response->getContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    // ---- Preview writes nothing ----

    public function test_uploading_checks_the_file_but_creates_no_accounts(): void
    {
        $content = $this->csv([$this->good('FUL/PHY/25/001'), 'FUL/PHY/25/002,,female,regular,,2025/2026,2025111111XX,,,,']);

        $this->upload($content)->assertRedirect();
        $import = StudentImport::firstOrFail();

        $this->assertSame(2, $import->total_rows);
        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(1, $import->error_rows);
        $this->assertSame('previewed', $import->status);
        $this->assertSame(0, User::where('role', 'student')->count());
        Storage::disk('local')->assertExists($import->file_path);
        $this->actingAs($this->eo)->get("/admin/students/import/{$import->id}")->assertOk()->assertSee('Name is missing')->assertSee('FUL/PHY/25/001');
    }

    // ---- File level problems ----

    public function test_bad_files_are_refused_with_a_clear_message(): void
    {
        $this->upload("matric,name\nX1,Ada")->assertSessionHasErrors('file');
        $this->upload('')->assertSessionHasErrors('file');
        $this->upload(self::HEADER."\n")->assertSessionHasErrors('file');
        $this->actingAs($this->eo)->post('/admin/students/import', ['file' => UploadedFile::fake()->create('students.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertSame(0, StudentImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles('imports'), 'rejected files are not kept');
    }

    public function test_the_row_limit_is_enforced(): void
    {
        $rows = array_map(fn ($i) => $this->good("FUL/PHY/25/{$i}"), range(1, StudentImportService::MAX_ROWS + 1));

        $this->upload($this->csv($rows))->assertSessionHasErrors('file');
    }

    public function test_semicolons_a_byte_order_mark_and_loose_headers_are_understood(): void
    {
        $content = "\xEF\xBB\xBF".'Matric No;Full Name;Sex;Mode;Session;JAMB'."\n".'FUL/1;Ada Obi;F;UTME;2025/2026;2025123456AB'."\n";
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        $parsed = app(StudentImportService::class)->parse($path);
        unlink($path);
        $this->assertNull($parsed['error']);

        $result = app(StudentImportService::class)->validate($parsed['rows'], null);

        $this->assertSame([], $result['errors']);
        $row = array_values($result['valid'])[0];
        $this->assertSame('female', $row['gender']);
        $this->assertSame('regular', $row['entry_mode']);
        $this->assertSame(100, $row['entry_level']);
    }

    public function test_blank_lines_are_ignored_and_stray_spaces_trimmed(): void
    {
        $result = $this->validate(['', '  FUL/PHY/25/001  ,  Ada   Obi ,female,regular,,2025/2026, 2025123456AB ,,,,', '']);

        $this->assertSame([], $result['errors']);
        $row = array_values($result['valid'])[0];
        $this->assertSame('FUL/PHY/25/001', $row['matric']);
        $this->assertSame('Ada Obi', $row['name']);
        $this->assertSame('2025123456AB', $row['jamb_reg_no']);
    }

    // ---- Row rules ----

    public function test_entry_levels_follow_the_admission_mode(): void
    {
        $result = $this->validate([
            'A1,Reg One,male,regular,,2025/2026,J1,,,,',
            'A2,De One,male,direct_entry,,2025/2026,J2,,,,',
            'A3,Tr Two,male,transfer,200,2025/2026,,,,,',
            'A4,Tr One,male,Transfer,100,2025/2026,,,,,',
            'A5,Bad De,male,direct entry,100,2025/2026,J5,,,,',
            'A6,Bad Tr,male,transfer,,2025/2026,,,,,',
            'A7,Bad Tr3,male,transfer,300,2025/2026,,,,,',
            'A8,Bad Mode,male,visitor,,2025/2026,J8,,,,',
        ]);

        $levels = collect($result['valid'])->pluck('entry_level', 'matric')->all();
        $this->assertSame(['A1' => 100, 'A2' => 200, 'A3' => 200, 'A4' => 100], $levels);
        $this->assertSame(['A5', 'A6', 'A7', 'A8'], collect($result['errors'])->pluck('matric')->all());
    }

    public function test_required_fields_and_formats_are_checked(): void
    {
        $result = $this->validate([
            ',No Matric,male,regular,,2025/2026,J1,,,,',
            'B2,,male,regular,,2025/2026,J2,,,,',
            'B3,No Gender,,regular,,2025/2026,J3,,,,',
            'B4,Odd Gender,other,regular,,2025/2026,J4,,,,',
            'B5,No Jamb,male,regular,,2025/2026,,,,,',
            'B6,Bad Email,male,regular,,2025/2026,J6,not-an-email,,,',
            'B7,Fine,male,regular,,2025/2026,J7,,,,',
        ]);

        $this->assertSame(['B7'], collect($result['valid'])->pluck('matric')->all());
        $this->assertCount(6, $result['errors']);
    }

    public function test_duplicates_in_the_file_and_in_the_database_are_caught(): void
    {
        User::factory()->create(['identifier' => 'FUL/EXISTING', 'email' => 'taken@example.com']);
        $result = $this->validate([
            'C1,First,male,regular,,2025/2026,J1,same@example.com,,,',
            'c1,Second,male,regular,,2025/2026,J2,,,,',
            'C2,Third,male,regular,,2025/2026,J3,SAME@example.com,,,',
            'ful/existing,Fourth,male,regular,,2025/2026,J4,,,,',
            'C4,Fifth,male,regular,,2025/2026,J5,taken@example.com,,,',
        ]);

        $this->assertSame(['C1'], collect($result['valid'])->pluck('matric')->all());
        $messages = collect($result['errors'])->mapWithKeys(fn ($e) => [$e['matric'] => implode(' ', $e['messages'])]);
        $this->assertStringContainsString('Repeated in the file', $messages['c1']);
        $this->assertStringContainsString('Email repeated', $messages['C2']);
        $this->assertStringContainsString('already exists', $messages['ful/existing']);
        $this->assertStringContainsString('email already belongs', $messages['C4']);
    }

    public function test_sessions_come_from_the_row_or_the_default_and_must_exist(): void
    {
        $other = AcademicSession::create(['name' => '2021/2022']);
        $result = $this->validate([
            'D1,Own Session,male,regular,,2021/2022,J1,,,,',
            'D2,Default Session,male,regular,,,J2,,,,',
            'D3,Missing Session,male,regular,,1999/2000,J3,,,,',
        ]);

        $this->assertSame($other->id, $result['valid'][2]['session_id']);
        $this->assertSame($this->session->id, $result['valid'][3]['session_id']);
        $this->assertStringContainsString('does not exist', $result['errors'][0]['messages'][0]);

        $noDefault = app(StudentImportService::class)->validate([2 => ['matric' => 'E1', 'name' => 'x', 'gender' => 'male', 'entry_mode' => 'regular', 'jamb_reg_no' => 'J']], null);
        $this->assertStringContainsString('no default session', $noDefault['errors'][0]['messages'][0]);
    }

    public function test_existing_students_can_be_imported_at_their_current_level_with_a_specialization(): void
    {
        Specialization::create(['name' => 'Geophysics']);
        Specialization::create(['name' => 'Photonics', 'is_active' => false]);
        $result = $this->validate([
            'F1,Final Year,female,regular,,2021/2022,J1,,,400,geophysics',
            'F2,Third Year,female,regular,,2021/2022,J2,,,300,',
            'F3,Backwards,female,direct_entry,,2021/2022,J3,,,100,',
            'F4,Odd Level,female,regular,,2021/2022,J4,,,500,',
            'F5,Early Area,female,regular,,2021/2022,J5,,,200,Geophysics',
            'F6,Unknown Area,female,regular,,2021/2022,J6,,,400,Astrology',
            'F7,Inactive Area,female,regular,,2021/2022,J7,,,400,Photonics',
        ], AcademicSession::create(['name' => '2021/2022']));

        $valid = collect($result['valid'])->keyBy('matric');
        $this->assertSame(400, $valid['F1']['current_level']);
        $this->assertNotNull($valid['F1']['specialization_id']);
        $this->assertSame(300, $valid['F2']['current_level']);
        $this->assertSame(['F3', 'F4', 'F5', 'F6', 'F7'], collect($result['errors'])->pluck('matric')->all());
    }

    // ---- Confirm ----

    public function test_confirming_creates_accounts_with_temporary_passwords_and_skips_bad_rows(): void
    {
        Specialization::create(['name' => 'Geophysics']);
        $this->upload($this->csv([
            'G1,Ada Obi,female,regular,,2025/2026,2025123456AB,ada@example.com,0801,,',
            'G2,Musa Bello,male,direct_entry,,2025/2026,2025654321CD,,,300,',
            'G3,Chidi Eze,male,transfer,200,2025/2026,,,,400,Geophysics',
            'G4,,male,regular,,2025/2026,J4,,,,',
        ]));
        $import = StudentImport::firstOrFail();

        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm")->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(3, User::where('role', 'student')->count());
        $this->assertSame(0, User::where('identifier', 'G4')->count());

        $ada = User::where('identifier', 'G1')->firstOrFail();
        $this->assertTrue($ada->must_change_password);
        $this->assertSame('ada@example.com', $ada->email);
        $this->assertSame(100, $ada->studentProfile->current_level);
        $this->assertSame('active', $ada->studentProfile->status);
        $this->assertSame($this->session->id, $ada->studentProfile->entry_session_id);

        $musa = User::where('identifier', 'G2')->firstOrFail();
        $this->assertSame('g2@students.invalid', $musa->email, 'no email: a placeholder is used');
        $this->assertSame(200, $musa->studentProfile->entry_level);
        $this->assertSame(300, $musa->studentProfile->current_level);

        $chidi = User::where('identifier', 'G3')->firstOrFail()->studentProfile;
        $this->assertSame(400, $chidi->current_level);
        $this->assertNotNull($chidi->specialization_id);
        $this->assertTrue($chidi->isTransfer());

        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertSame(3, $import->created_count);
        $this->assertSame(1, $import->error_rows);
        $this->assertNull($import->file_path, 'the uploaded CSV is deleted');
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'students.imported']);
    }

    public function test_the_temporary_passwords_are_downloadable_once_and_really_work(): void
    {
        $this->upload($this->csv([$this->good('H1'), $this->good('H2')]));
        $import = StudentImport::firstOrFail();
        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm");

        $response = $this->actingAs($this->eo)->get("/admin/students/import/{$import->id}/credentials")->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($response->getContent()))));
        $this->assertSame(['matric', 'name', 'temporary_password'], $lines[0]);
        $this->assertCount(3, $lines);

        foreach (array_slice($lines, 1) as [$matric, , $password]) {
            $this->assertTrue(Hash::check($password, User::where('identifier', $matric)->value('password')), "password for {$matric}");
        }

        // One time only.
        $this->actingAs($this->eo)->get("/admin/students/import/{$import->id}/credentials")->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('import-credentials'));
        $this->assertNotNull($import->fresh()->credentials_downloaded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'students.import_credentials_downloaded']);
    }

    public function test_only_the_uploader_or_the_hod_can_see_an_import_and_its_passwords(): void
    {
        $this->upload($this->csv([$this->good('I1')]));
        $import = StudentImport::firstOrFail();
        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm");

        $other = User::factory()->create(['role' => User::ROLE_ADMIN, 'permissions' => ['manage_students']]);
        $this->actingAs($other)->get("/admin/students/import/{$import->id}")->assertForbidden();
        $this->actingAs($other)->get("/admin/students/import/{$import->id}/credentials")->assertForbidden();
        $this->actingAs($other)->post("/admin/students/import/{$import->id}/confirm")->assertForbidden();
        $this->actingAs($this->hod)->get("/admin/students/import/{$import->id}")->assertOk();
    }

    public function test_a_confirmed_import_cannot_be_run_twice(): void
    {
        $this->upload($this->csv([$this->good('J1')]));
        $import = StudentImport::firstOrFail();

        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm");
        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm")->assertNotFound();
        $this->assertSame(1, User::where('identifier', 'J1')->count());
    }

    public function test_confirming_rechecks_so_a_student_added_since_the_preview_is_skipped(): void
    {
        $this->upload($this->csv([$this->good('K1'), $this->good('K2')]));
        $import = StudentImport::firstOrFail();
        $this->assertSame(2, $import->valid_rows);

        User::factory()->create(['identifier' => 'K1']); // appears after the preview

        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm")->assertSessionHasNoErrors();

        $this->assertSame(1, StudentProfile::count());
        $this->assertSame(1, $import->fresh()->created_count);
        $this->assertSame(1, $import->fresh()->error_rows);
    }

    public function test_nothing_is_created_when_no_row_is_valid(): void
    {
        $this->upload($this->csv(['L1,,male,regular,,2025/2026,J1,,,,']));
        $import = StudentImport::firstOrFail();

        $this->actingAs($this->eo)->get("/admin/students/import/{$import->id}")->assertSee('No row can be imported');
        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm")->assertSessionHasNoErrors();

        $this->assertSame(0, User::where('role', 'student')->count());
        $this->assertNull($import->fresh()->credentials_path);
        $this->assertSame([], Storage::disk('local')->allFiles('import-credentials'));
    }

    public function test_the_problem_report_downloads_as_csv(): void
    {
        $this->upload($this->csv(['M1,,male,regular,,2025/2026,J1,,,,']));
        $import = StudentImport::firstOrFail();

        $response = $this->actingAs($this->eo)->get("/admin/students/import/{$import->id}/errors")->assertOk();

        $this->assertStringStartsWith('line,matric,problem', $response->getContent());
        $this->assertStringContainsString('Name is missing', $response->getContent());
    }

    // ---- Safety ----

    public function test_spreadsheet_formulas_cannot_ride_in_the_exported_files(): void
    {
        $svc = app(StudentImportService::class);

        $this->assertSame("'=HYPERLINK(\"http://evil\")", $svc->safeCell('=HYPERLINK("http://evil")'));
        $this->assertSame("'+1+1", $svc->safeCell('+1+1'));
        $this->assertSame("'@SUM(A1)", $svc->safeCell('@SUM(A1)'));
        $this->assertSame("'-2", $svc->safeCell('-2'));
        $this->assertSame('Ada Obi', $svc->safeCell('Ada Obi'));

        $this->upload($this->csv(['N1,=cmd|calc,male,regular,,2025/2026,J1,,,,']));
        $import = StudentImport::firstOrFail();
        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm");
        $csv = $this->actingAs($this->eo)->get("/admin/students/import/{$import->id}/credentials")->getContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
    }

    public function test_the_purge_command_removes_old_files_only(): void
    {
        Storage::disk('local')->put('imports/old.csv', 'x');
        Storage::disk('local')->put('import-credentials/old.csv', 'x');
        Storage::disk('local')->put('imports/new.csv', 'x');
        $old = StudentImport::create(['user_id' => $this->eo->id, 'original_name' => 'a.csv', 'file_path' => 'imports/old.csv', 'credentials_path' => 'import-credentials/old.csv']);
        $new = StudentImport::create(['user_id' => $this->eo->id, 'original_name' => 'b.csv', 'file_path' => 'imports/new.csv']);
        StudentImport::whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);

        $this->artisan('imports:purge')->assertSuccessful();

        Storage::disk('local')->assertMissing('imports/old.csv');
        Storage::disk('local')->assertMissing('import-credentials/old.csv');
        Storage::disk('local')->assertExists('imports/new.csv');
        $this->assertNull($old->fresh()->file_path);
        $this->assertNotNull($new->fresh()->file_path);
    }

    public function test_imported_students_can_sign_in_and_must_change_their_password(): void
    {
        $this->upload($this->csv([$this->good('P1')]));
        $import = StudentImport::firstOrFail();
        $this->actingAs($this->eo)->post("/admin/students/import/{$import->id}/confirm");
        [, $line] = array_map('str_getcsv', array_filter(explode("\n", trim($this->actingAs($this->eo)->get("/admin/students/import/{$import->id}/credentials")->getContent()))));
        auth()->logout();

        $this->post('/login', ['login' => 'P1', 'password' => $line[2]])->assertRedirect();
        $this->get('/student')->assertRedirect(route('security.password'));
    }
}
