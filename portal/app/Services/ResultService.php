<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Result;
use App\Models\User;
use App\Notifications\ResultsPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Score entry and the result-sheet workflow:
 * draft -> submitted -> vetted -> approved -> published (HoD can reopen to draft).
 */
class ResultService
{
    public function __construct(private GradingService $grading) {}

    /**
     * Save scores for registrations in the sheet. Only approved registrations of this offering are accepted.
     *
     * @param  array<int, array{ca?: mixed, exam?: mixed, flag?: ?string}>  $rows  keyed by course_registration id
     */
    public function saveScores(CourseOffering $offering, array $rows): int
    {
        $this->requireStatus($offering, [CourseOffering::DRAFT], 'Scores can only be edited while the sheet is a draft.');

        $valid = $offering->approvedRegistrations()->pluck('id')->flip();
        $saved = 0;

        DB::transaction(function () use ($offering, $rows, $valid, &$saved) {
            foreach ($rows as $registrationId => $row) {
                if (! $valid->has((int) $registrationId)) {
                    continue;
                }
                $ca = $this->number($row['ca'] ?? null);   // CA 1
                $ca2 = $this->number($row['ca2'] ?? null); // CA 2 (optional)
                $exam = $this->number($row['exam'] ?? null);
                $flag = ($row['flag'] ?? '') ?: null;

                if ($flag !== null && ! in_array($flag, Result::FLAGS, true)) {
                    throw ValidationException::withMessages(['scores' => "Unknown flag \"{$flag}\"."]);
                }
                $this->checkRange('CA 1', $ca, config('portal.ca_max'));
                $this->checkRange('CA 2', $ca2, config('portal.ca_max'));
                if (($ca ?? 0) + ($ca2 ?? 0) > config('portal.ca_max')) {
                    throw ValidationException::withMessages(['scores' => 'CA 1 and CA 2 together must not be more than '.config('portal.ca_max').'.']);
                }
                $this->checkRange('Exam', $exam, config('portal.exam_max'));

                // Untouched blank rows do not create a result.
                if ($ca === null && $ca2 === null && $exam === null && $flag === null) {
                    Result::where('course_registration_id', $registrationId)->delete();

                    continue;
                }

                Result::updateOrCreate(
                    ['course_registration_id' => (int) $registrationId],
                    ['course_offering_id' => $offering->id, 'ca' => $ca, 'ca2' => $ca2, 'exam' => $exam, 'flag' => $flag]
                        + $this->grading->evaluate($ca, $exam, $flag, $ca2),
                );
                $saved++;
            }
        });

        return $saved;
    }

    /**
     * Import scores from a CSV. Columns: matric, CA 1, CA 2, exam. The older three-column layout
     * (matric, CA, exam) still works, and a header row (matric, ca1, ca2, exam) may be used in any order.
     * Returns [rows applied, problems].
     *
     * @return array{0: int, 1: array<int, string>}
     */
    public function importCsv(CourseOffering $offering, string $path): array
    {
        $byMatric = $offering->approvedRegistrations()->with('student.user')->get()
            ->keyBy(fn ($r) => strtoupper(trim($r->student->user->identifier)));

        $rows = [];
        $problems = [];
        $handle = fopen($path, 'r');
        $line = 0;
        $map = null;
        while (($cols = fgetcsv($handle)) !== false) {
            $line++;
            if ($cols === [null] || count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            // A header row names the columns; otherwise guess from how many there are.
            if ($line === 1 && ! is_numeric($cols[1] ?? null)) {
                $map = $this->csvMap($cols);

                continue;
            }
            $map ??= count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) >= 4
                ? ['matric' => 0, 'ca' => 1, 'ca2' => 2, 'exam' => 3]
                : ['matric' => 0, 'ca' => 1, 'ca2' => null, 'exam' => 2];

            $matric = strtoupper(trim($cols[$map['matric']] ?? ''));
            $registration = $byMatric->get($matric);
            if (! $registration) {
                $problems[] = "Line {$line}: {$matric} has no approved registration for this course.";

                continue;
            }
            $rows[$registration->id] = [
                'ca' => $cols[$map['ca']] ?? null,
                'ca2' => $map['ca2'] !== null ? ($cols[$map['ca2']] ?? null) : null,
                'exam' => $cols[$map['exam']] ?? null,
            ];
        }
        fclose($handle);
        $applied = count($rows);

        // Keep scores already entered for students not in the file.
        $existing = Result::where('course_offering_id', $offering->id)->get()->keyBy('course_registration_id');
        foreach ($existing as $registrationId => $result) {
            $rows[$registrationId] ??= ['ca' => $result->ca, 'ca2' => $result->ca2, 'exam' => $result->exam, 'flag' => $result->flag];
        }

        $this->saveScores($offering, $rows);

        return [$applied, $problems];
    }

    public function submit(CourseOffering $offering): void
    {
        $this->requireStatus($offering, [CourseOffering::DRAFT], 'Only a draft sheet can be submitted.');

        $registrations = $offering->approvedRegistrations()->with('student.user')->get();
        if ($registrations->isEmpty()) {
            throw ValidationException::withMessages(['scores' => 'No approved registrations for this course yet.']);
        }

        $results = Result::where('course_offering_id', $offering->id)->get()->keyBy('course_registration_id');
        $missing = $registrations->filter(function ($r) use ($results) {
            $res = $results->get($r->id);

            // Complete = a total, or an explicit flag (absent, incomplete, withheld).
            return ! $res || ($res->total === null && $res->flag === null);
        });

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'scores' => 'Scores or a flag are missing for: '.$missing->map(fn ($r) => $r->student->user->identifier)->implode(', ').'.',
            ]);
        }

        $this->move($offering, CourseOffering::SUBMITTED, 'results.submitted', ['submitted_at' => now(), 'return_note' => null]);
    }

    public function vet(CourseOffering $offering, User $by): void
    {
        $this->requireStatus($offering, [CourseOffering::SUBMITTED], 'Only a submitted sheet can be vetted.');
        $this->move($offering, CourseOffering::VETTED, 'results.vetted', ['vetted_by' => $by->id, 'vetted_at' => now()]);
    }

    public function returnToDraft(CourseOffering $offering, string $note): void
    {
        $this->requireStatus($offering, [CourseOffering::SUBMITTED, CourseOffering::VETTED], 'Only a submitted or vetted sheet can be returned.');
        $this->move($offering, CourseOffering::DRAFT, 'results.returned', ['return_note' => $note, 'vetted_by' => null, 'vetted_at' => null]);
    }

    public function approve(CourseOffering $offering, User $by): void
    {
        $this->requireStatus($offering, [CourseOffering::VETTED], 'Only a vetted sheet can be approved.');
        $this->move($offering, CourseOffering::APPROVED, 'results.approved', ['approved_by' => $by->id, 'approved_at' => now()]);
    }

    public function publish(CourseOffering $offering): void
    {
        $this->requireStatus($offering, [CourseOffering::APPROVED], 'Only an approved sheet can be published.');
        $this->move($offering, CourseOffering::PUBLISHED, 'results.published', ['published_at' => now()]);

        $offering->load('course', 'session');
        $offering->approvedRegistrations()->with('student.user')->get()
            ->each(fn ($r) => $r->student->user->notify(new ResultsPublished($offering)));
    }

    /** HoD escape hatch for corrections: back to draft with a recorded reason. */
    public function reopen(CourseOffering $offering, string $reason): void
    {
        $this->requireStatus(
            $offering,
            [CourseOffering::SUBMITTED, CourseOffering::VETTED, CourseOffering::APPROVED, CourseOffering::PUBLISHED],
            'This sheet is already a draft.'
        );
        $was = $offering->status;
        $this->move($offering, CourseOffering::DRAFT, 'results.reopened', [
            'return_note' => 'Reopened by the HoD: '.$reason,
            'vetted_by' => null, 'vetted_at' => null, 'approved_by' => null, 'approved_at' => null, 'published_at' => null,
        ], ['was' => $was, 'reason' => $reason]);
    }

    private function move(CourseOffering $offering, string $status, string $action, array $fields = [], array $extra = []): void
    {
        $offering->update(['status' => $status] + $fields);
        AuditLog::record($action, ['offering_id' => $offering->id, 'course' => $offering->course->code ?? null] + $extra);
    }

    private function requireStatus(CourseOffering $offering, array $allowed, string $message): void
    {
        if (! in_array($offering->status, $allowed, true)) {
            throw ValidationException::withMessages(['scores' => $message]);
        }
    }

    /** @param array<int, string|null> $header */
    private function csvMap(array $header): array
    {
        $map = ['matric' => 0, 'ca' => 1, 'ca2' => null, 'exam' => 2];
        foreach ($header as $i => $name) {
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $name));
            match (true) {
                str_contains($key, 'matric') => $map['matric'] = $i,
                in_array($key, ['ca1', 'ca', 'catest1'], true) => $map['ca'] = $i,
                in_array($key, ['ca2', 'catest2'], true) => $map['ca2'] = $i,
                str_contains($key, 'exam') => $map['exam'] = $i,
                default => null,
            };
        }

        return $map;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(['scores' => "\"{$value}\" is not a valid score."]);
        }

        return (float) $value;
    }

    private function checkRange(string $label, ?float $value, int|float $max): void
    {
        if ($value !== null && ($value < 0 || $value > $max)) {
            throw ValidationException::withMessages(['scores' => "{$label} must be between 0 and {$max}."]);
        }
    }
}
