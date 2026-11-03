<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reads, checks and imports a CSV of students. Checking never writes anything. */
class StudentImportService
{
    public const MAX_ROWS = 2000;

    /** Columns the CSV must have (after aliases). */
    public const REQUIRED = ['matric', 'name', 'gender', 'entry_mode'];

    public const TEMPLATE_HEADER = ['matric', 'name', 'gender', 'entry_mode', 'entry_level', 'session', 'jamb_reg_no', 'email', 'phone', 'current_level', 'specialization'];

    private const ALIASES = [
        'matric' => ['matric', 'matric_no', 'matric_number', 'matriculation_number', 'matric_no.'],
        'name' => ['name', 'full_name', 'fullname', 'student_name'],
        'gender' => ['gender', 'sex'],
        'entry_mode' => ['entry_mode', 'admission_mode', 'mode', 'mode_of_entry'],
        'entry_level' => ['entry_level', 'admitted_level'],
        'session' => ['session', 'entry_session', 'admission_session', 'session_admitted'],
        'jamb_reg_no' => ['jamb_reg_no', 'jamb', 'jamb_number', 'jamb_reg', 'jamb_no'],
        'email' => ['email', 'email_address'],
        'phone' => ['phone', 'phone_number', 'telephone'],
        'current_level' => ['current_level', 'level'],
        'specialization' => ['specialization', 'area_of_specialization', 'area'],
    ];

    /**
     * Read the file into rows keyed by canonical column names.
     *
     * @return array{rows: array<int, array<string, string>>, error: ?string}  rows keyed by the CSV line number
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return ['rows' => [], 'error' => 'The file could not be read.'];
        }

        $first = fgets($handle);
        if ($first === false || trim($first) === '') {
            fclose($handle);

            return ['rows' => [], 'error' => 'The file is empty.'];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // Excel's byte order mark
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => $this->canonical((string) $h), str_getcsv(rtrim($first, "\r\n"), $delimiter));

        $missing = array_values(array_diff(self::REQUIRED, $header));
        if ($missing) {
            fclose($handle);

            return ['rows' => [], 'error' => 'Missing column(s): '.implode(', ', $missing).'. Download the template to see the expected columns.'];
        }

        $rows = [];
        $line = 1;
        while (($cols = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if ($cols === [null] || count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                return ['rows' => [], 'error' => 'The file has more than '.self::MAX_ROWS.' students. Split it into smaller files.'];
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name !== '' && ! array_key_exists($name, $row)) {
                    $row[$name] = $this->clean((string) ($cols[$i] ?? ''));
                }
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return $rows ? ['rows' => $rows, 'error' => null] : ['rows' => [], 'error' => 'The file has a header but no students.'];
    }

    /**
     * Check every row. Nothing is written.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{valid: array<int, array<string, mixed>>, errors: array<int, array{line: int, matric: string, messages: array<int, string>}>}
     */
    public function validate(array $rows, ?AcademicSession $defaultSession): array
    {
        $sessions = AcademicSession::pluck('id', 'name');
        $areas = Specialization::where('is_active', true)->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [Str::lower($n) => $id]);
        $takenMatrics = User::whereNotNull('identifier')->pluck('identifier')->mapWithKeys(fn ($m) => [Str::upper($m) => true]);
        $takenEmails = User::pluck('email')->mapWithKeys(fn ($e) => [Str::lower($e) => true]);

        $valid = [];
        $errors = [];
        $seenMatrics = [];
        $seenEmails = [];

        foreach ($rows as $line => $row) {
            $messages = [];
            $matric = $row['matric'] ?? '';

            if ($matric === '') {
                $messages[] = 'Matric number is missing.';
            } elseif (mb_strlen($matric) > 40) {
                $messages[] = 'Matric number is too long.';
            } elseif ($takenMatrics->has(Str::upper($matric))) {
                $messages[] = 'This matric number already exists.';
            } elseif (isset($seenMatrics[Str::upper($matric)])) {
                $messages[] = "Repeated in the file (first on line {$seenMatrics[Str::upper($matric)]}).";
            }

            $name = $row['name'] ?? '';
            if ($name === '' || mb_strlen($name) > 150) {
                $messages[] = $name === '' ? 'Name is missing.' : 'Name is too long.';
            }

            $gender = $this->gender($row['gender'] ?? '');
            if ($gender === null) {
                $messages[] = 'Gender must be male or female.';
            }

            $mode = $this->mode($row['entry_mode'] ?? '');
            if ($mode === null) {
                $messages[] = 'Entry mode must be regular, direct entry or transfer.';
            }

            $entryLevel = null;
            if ($mode) {
                $given = ($row['entry_level'] ?? '') === '' ? null : (int) preg_replace('/\D/', '', $row['entry_level']);
                $fixed = StudentProfile::entryLevelFor($mode);
                if ($fixed !== null) {
                    $entryLevel = $fixed;
                    if ($given !== null && $given !== $fixed) {
                        $messages[] = "A {$mode} student enters at {$fixed} level, not {$given}.";
                    }
                } elseif (! in_array($given, [100, 200], true)) {
                    $messages[] = 'A transfer student needs an entry level of 100 or 200.';
                } else {
                    $entryLevel = $given;
                }
            }

            $sessionName = ($row['session'] ?? '') !== '' ? $row['session'] : $defaultSession?->name;
            $sessionId = $sessionName !== null ? ($sessions[$sessionName] ?? null) : null;
            if ($sessionName === null) {
                $messages[] = 'Session is missing and no default session was chosen.';
            } elseif ($sessionId === null) {
                $messages[] = "Session \"{$sessionName}\" does not exist. Add it under Sessions first.";
            }

            $jamb = $row['jamb_reg_no'] ?? '';
            if ($mode && $mode !== StudentProfile::ENTRY_TRANSFER && $jamb === '') {
                $messages[] = 'JAMB registration number is required for JAMB admissions.';
            }
            if (mb_strlen($jamb) > 30) {
                $messages[] = 'JAMB number is too long.';
            }

            $email = $row['email'] ?? '';
            if ($email !== '') {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                    $messages[] = 'Email is not valid.';
                } elseif ($takenEmails->has(Str::lower($email))) {
                    $messages[] = 'This email already belongs to another account.';
                } elseif (isset($seenEmails[Str::lower($email)])) {
                    $messages[] = "Email repeated in the file (first on line {$seenEmails[Str::lower($email)]}).";
                }
            }

            $level = $entryLevel;
            if (($row['current_level'] ?? '') !== '') {
                $level = (int) preg_replace('/\D/', '', $row['current_level']);
                if (! in_array($level, [100, 200, 300, 400], true)) {
                    $messages[] = 'Current level must be 100, 200, 300 or 400.';
                    $level = null;
                } elseif ($entryLevel !== null && $level < $entryLevel) {
                    $messages[] = "Current level ({$level}) is below the entry level ({$entryLevel}).";
                }
            }

            $areaId = null;
            if (($row['specialization'] ?? '') !== '') {
                $areaId = $areas[Str::lower($row['specialization'])] ?? null;
                if ($areaId === null) {
                    $messages[] = "Unknown area of specialization \"{$row['specialization']}\".";
                } elseif ($level !== null && $level !== 400) {
                    $messages[] = 'Specialization applies to 400 level students only.';
                }
            }

            if ($messages) {
                $errors[] = ['line' => $line, 'matric' => $matric, 'messages' => $messages];

                continue;
            }

            $seenMatrics[Str::upper($matric)] = $line;
            if ($email !== '') {
                $seenEmails[Str::lower($email)] = $line;
            }
            $valid[$line] = [
                'matric' => $matric, 'name' => $name, 'gender' => $gender, 'entry_mode' => $mode, 'entry_level' => $entryLevel,
                'session_id' => $sessionId, 'jamb_reg_no' => $jamb ?: null, 'email' => $email ?: null,
                'phone' => ($row['phone'] ?? '') ?: null, 'current_level' => $level, 'specialization_id' => $areaId,
            ];
        }

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * Create the accounts in one transaction. Returns the one-time credentials.
     *
     * @param  array<int, array<string, mixed>>  $valid
     * @return array<int, array{matric: string, name: string, password: string}>
     */
    public function import(array $valid): array
    {
        $credentials = [];

        DB::transaction(function () use ($valid, &$credentials) {
            foreach ($valid as $row) {
                $password = Str::password(10, symbols: false);
                $user = User::create([
                    'name' => $row['name'], 'identifier' => $row['matric'],
                    'email' => $row['email'] ?: Str::slug($row['matric']).'@students.invalid',
                    'role' => User::ROLE_STUDENT, 'password' => $password, 'must_change_password' => true,
                ]);
                StudentProfile::create([
                    'user_id' => $user->id, 'entry_mode' => $row['entry_mode'], 'entry_level' => $row['entry_level'],
                    'entry_session_id' => $row['session_id'], 'jamb_reg_no' => $row['jamb_reg_no'], 'gender' => $row['gender'],
                    'phone' => $row['phone'], 'current_level' => $row['current_level'], 'specialization_id' => $row['specialization_id'],
                ]);
                $credentials[] = ['matric' => $row['matric'], 'name' => $row['name'], 'password' => $password];
            }
        });

        return $credentials;
    }

    /** A CSV the EO can open in Excel. Cells that start with = + - @ are neutralised so they cannot run as formulas. */
    public function csv(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => $this->safeCell((string) $v), $row));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function canonical(string $header): string
    {
        $key = Str::lower(trim(preg_replace('/[\s\-]+/', '_', $header), '_'));
        foreach (self::ALIASES as $canonical => $aliases) {
            if (in_array($key, $aliases, true)) {
                return $canonical;
            }
        }

        return '';
    }

    private function clean(string $value): string
    {
        // Trim, collapse spaces, drop control characters.
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value)));
    }

    private function gender(string $value): ?string
    {
        return match (Str::lower($value)) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => null,
        };
    }

    private function mode(string $value): ?string
    {
        return match (Str::lower(preg_replace('/[\s\-_]+/', '', $value))) {
            'regular', 'utme', 'jamb', 'ume' => StudentProfile::ENTRY_REGULAR,
            'directentry', 'de' => StudentProfile::ENTRY_DIRECT,
            'transfer', 'tr' => StudentProfile::ENTRY_TRANSFER,
            default => null,
        };
    }
}
