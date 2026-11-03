<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Graduate;
use App\Models\GraduateAmendment;
use App\Models\GraduateResult;
use App\Models\Specialization;
use App\Models\User;
use App\Notifications\PortalMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrections to the permanent graduate register. The original rows are never touched: a correction is
 * requested (EO or HoD), approved by the HoD, and then laid over the original every time the record is read.
 */
class GraduateAmendmentService
{
    /** Fields of the record that may be corrected. The class of degree, CGPA and credits follow from the results. */
    public const FIELDS = [
        'name' => 'Name',
        'gender' => 'Gender',
        'matric' => 'Matric number',
        'entry_session' => 'Session admitted',
        'specialization' => 'Area of specialization',
        'graduation_session' => 'Graduation session',
        'graduation_year' => 'Graduation year',
        'degree' => 'Degree',
    ];

    public function __construct(private GradingService $grading, private GraduationService $graduation) {}

    // ---- Reading ----

    public function effective(Graduate $graduate): Graduate
    {
        $approved = GraduateAmendment::where('graduate_id', $graduate->id)->approved()->orderBy('decided_at')->orderBy('id')->get();

        return $this->apply($graduate, $approved);
    }

    /** The record with these amendments laid over it (used for the real record and for "what if" checks). */
    private function apply(Graduate $original, Collection $amendments): Graduate
    {
        $copy = clone $original;
        $copy->setRelation('amendments', $amendments);
        $original->loadMissing('results');

        $overrides = [];
        foreach ($amendments as $a) {
            if ($a->kind === 'field') {
                $copy->setAttribute($a->field, $a->new_value['value'] ?? null);
            } else {
                $overrides[$a->graduate_result_id] = $a->new_value;
            }
        }

        $rows = $original->results->map(function (GraduateResult $row) use ($overrides) {
            $row = clone $row;
            if (isset($overrides[$row->id])) {
                $o = $overrides[$row->id];
                $row->setAttribute('ca', $o['ca'] ?? null);
                $row->setAttribute('ca2', $o['ca2'] ?? null);
                $row->setAttribute('exam', $o['exam'] ?? null);
                $row->setAttribute('flag', ($o['flag'] ?? '') ?: null);
                $eval = $this->grading->evaluate($this->f($o['ca'] ?? null), $this->f($o['exam'] ?? null), ($o['flag'] ?? '') ?: null, $this->f($o['ca2'] ?? null));
                $row->setAttribute('total', $eval['total']);
                $row->setAttribute('grade', $eval['grade']);
                $row->setAttribute('grade_point', $eval['grade_point']);
                $row->setAttribute('corrected', true);
            }

            return $row;
        });
        $copy->setRelation('results', $rows);

        // Corrected scores change the CGPA, the credits passed and the class of degree.
        if ($overrides) {
            $stats = $this->stats($rows);
            $originalPassed = $this->passedUnits($original->results);
            $copy->setAttribute('cgpa', $stats['cgpa']);
            $copy->setAttribute('credits_passed', (int) $original->credits_passed + $stats['passed_units'] - $originalPassed);
            $copy->setAttribute('class_of_degree', $this->graduation->classOf($stats['cgpa']));
        }

        return $copy;
    }

    // ---- Requesting ----

    /** @param array<string, mixed> $input */
    public function requestField(Graduate $graduate, string $field, mixed $value, string $reason, User $by): GraduateAmendment
    {
        $this->validateField($graduate, $field, $value);
        $current = $this->effective($graduate)->getAttribute($field);

        return $this->create($graduate, $by, $reason, [
            'kind' => 'field', 'field' => $field,
            'old_value' => ['value' => $current], 'new_value' => ['value' => $this->cast($field, $value)],
        ]);
    }

    /** @param array{ca?: mixed, ca2?: mixed, exam?: mixed, flag?: ?string} $scores */
    public function requestResult(Graduate $graduate, int $resultId, array $scores, string $reason, User $by): GraduateAmendment
    {
        $row = GraduateResult::where('graduate_id', $graduate->id)->find($resultId)
            ?? throw ValidationException::withMessages(['graduate_result_id' => 'That result does not belong to this graduate.']);
        if ($row->waived || $row->exempted) {
            throw ValidationException::withMessages(['graduate_result_id' => 'A waived or exempted course has no score to correct.']);
        }

        $new = $this->cleanScores($scores);
        $current = $this->effective($graduate)->results->firstWhere('id', $resultId);
        $old = ['ca' => $current->ca, 'ca2' => $current->ca2, 'exam' => $current->exam, 'flag' => $current->flag];
        if ($this->sameScores($old, $new)) {
            throw ValidationException::withMessages(['exam' => 'That is the same as the current record.']);
        }

        $this->guardDegreeStillStands($graduate, [$resultId => $new]);

        return $this->create($graduate, $by, $reason, [
            'kind' => 'result', 'graduate_result_id' => $resultId, 'old_value' => $old, 'new_value' => $new,
        ]);
    }

    // ---- Deciding ----

    public function approve(GraduateAmendment $amendment, User $hod): void
    {
        $this->requireRequested($amendment);
        $graduate = $amendment->graduate;

        DB::transaction(function () use ($amendment, $graduate, $hod) {
            // Record what the register said immediately before this correction.
            $before = $this->effective($graduate);
            if ($amendment->kind === 'field') {
                $amendment->old_value = ['value' => $before->getAttribute($amendment->field)];
            } else {
                $this->guardDegreeStillStands($graduate, [$amendment->graduate_result_id => $amendment->new_value]);
                $row = $before->results->firstWhere('id', $amendment->graduate_result_id);
                $amendment->old_value = ['ca' => $row->ca, 'ca2' => $row->ca2, 'exam' => $row->exam, 'flag' => $row->flag];
            }
            $amendment->fill(['status' => 'approved', 'decided_by' => $hod->id, 'decided_at' => now()])->save();

            // The alumnus signs in under the live account, so a corrected name is corrected there too.
            if ($amendment->kind === 'field' && $amendment->field === 'name') {
                User::where('id', optional($graduate->studentProfile)->user_id)->update(['name' => $amendment->new_value['value']]);
            }
        });

        AuditLog::record('graduate.correction_approved', ['amendment_id' => $amendment->id, 'graduate_id' => $graduate->id, 'summary' => $amendment->summary()]);
        $this->notify($amendment, 'Correction approved', 'The correction you requested for '.$graduate->matric.' was approved.');
    }

    public function reject(GraduateAmendment $amendment, User $hod, string $note): void
    {
        $this->requireRequested($amendment);
        $amendment->fill(['status' => 'rejected', 'decided_by' => $hod->id, 'decided_at' => now(), 'decision_note' => $note])->save();

        AuditLog::record('graduate.correction_rejected', ['amendment_id' => $amendment->id, 'note' => $note]);
        $this->notify($amendment, 'Correction not approved', "The correction you requested for {$amendment->graduate->matric} was not approved: {$note}");
    }

    // ---- Checks ----

    private function validateField(Graduate $graduate, string $field, mixed $value): void
    {
        if (! array_key_exists($field, self::FIELDS)) {
            throw ValidationException::withMessages(['field' => 'That part of the record cannot be corrected here.']);
        }
        $value = is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : $value;
        $fail = fn (string $m) => throw ValidationException::withMessages(['value' => $m]);

        match ($field) {
            'name', 'degree' => (blank($value) || mb_strlen($value) > 150) ? $fail('Enter a value of up to 150 characters.') : null,
            'gender' => in_array($value, ['male', 'female'], true) ? null : $fail('Gender must be male or female.'),
            'graduation_year' => (is_numeric($value) && (int) $value >= 1990 && (int) $value <= (int) now()->addYear()->year) ? null : $fail('Enter a year between 1990 and next year.'),
            'entry_session', 'graduation_session' => preg_match('/^\d{4}\/\d{4}$/', (string) $value) ? null : $fail('Write the session like 2025/2026.'),
            'specialization' => Specialization::where('name', $value)->exists() ? null : $fail('Choose an existing area of specialization.'),
            'matric' => $this->validateMatric($graduate, (string) $value, $fail),
        };

        if ((string) $this->effective($graduate)->getAttribute($field) === (string) $this->cast($field, $value)) {
            $fail('That is the same as the current record.');
        }
    }

    private function validateMatric(Graduate $graduate, string $value, \Closure $fail): void
    {
        if ($value === '' || mb_strlen($value) > 40) {
            $fail('Enter a matric number of up to 40 characters.');
        }
        $taken = Graduate::where('id', '!=', $graduate->id)->where('matric', $value)->exists()
            || User::where('identifier', $value)->where('id', '!=', optional($graduate->studentProfile)->user_id)->exists()
            || GraduateAmendment::where('field', 'matric')->where('status', '!=', 'rejected')->where('graduate_id', '!=', $graduate->id)->where('new_value->value', $value)->exists();
        if ($taken) {
            $fail('Another record already uses that matric number.');
        }
    }

    private function cast(string $field, mixed $value): mixed
    {
        $value = is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : $value;

        return $field === 'graduation_year' ? (int) $value : $value;
    }

    /** @return array{ca: ?float, ca2: ?float, exam: ?float, flag: ?string} */
    private function cleanScores(array $scores): array
    {
        $num = function ($v, string $label, $max) {
            if ($v === null || trim((string) $v) === '') {
                return null;
            }
            if (! is_numeric($v) || $v < 0 || $v > $max) {
                throw ValidationException::withMessages(['exam' => "{$label} must be a number from 0 to {$max}."]);
            }

            return (float) $v;
        };
        $ca = $num($scores['ca'] ?? null, 'CA 1', config('portal.ca_max'));
        $ca2 = $num($scores['ca2'] ?? null, 'CA 2', config('portal.ca_max'));
        $exam = $num($scores['exam'] ?? null, 'Exam', config('portal.exam_max'));
        $flag = ($scores['flag'] ?? '') ?: null;

        if ($flag !== null && ! in_array($flag, ['absent', 'incomplete', 'withheld'], true)) {
            throw ValidationException::withMessages(['exam' => 'Unknown flag.']);
        }
        if (($ca ?? 0) + ($ca2 ?? 0) > config('portal.ca_max')) {
            throw ValidationException::withMessages(['exam' => 'CA 1 and CA 2 together must not be more than '.config('portal.ca_max').'.']);
        }
        if ($flag === null && ($ca === null || $exam === null)) {
            throw ValidationException::withMessages(['exam' => 'Give CA 1 and the exam score, or choose a flag.']);
        }

        return compact('ca', 'ca2', 'exam', 'flag');
    }

    private function sameScores(array $a, array $b): bool
    {
        $n = fn ($v) => $v === null || $v === '' ? null : (float) $v;

        return $n($a['ca'] ?? null) === $n($b['ca']) && $n($a['ca2'] ?? null) === $n($b['ca2'])
            && $n($a['exam'] ?? null) === $n($b['exam']) && (($a['flag'] ?? '') ?: null) === $b['flag'];
    }

    /** A correction may change the class, but it may not take the CGPA below the lowest class: that is a different process. */
    private function guardDegreeStillStands(Graduate $graduate, array $extraOverrides): void
    {
        $graduate->loadMissing('results');
        $approved = GraduateAmendment::where('graduate_id', $graduate->id)->approved()->get();
        $hypothetical = $approved->concat(collect($extraOverrides)->map(fn ($new, $id) => new GraduateAmendment([
            'graduate_id' => $graduate->id, 'kind' => 'result', 'graduate_result_id' => $id, 'new_value' => $new, 'status' => 'approved',
        ])));

        $min = collect(config('portal.degree_classes'))->last()[0];
        if ($this->apply($graduate, $hypothetical)->cgpa < $min) {
            throw ValidationException::withMessages(['exam' => 'This correction would take the CGPA below '.number_format($min, 2).', the lowest pass for the degree. Withdrawing a degree is outside this tool; speak to the HoD.']);
        }
    }

    // ---- Maths on the snapshot ----

    /** @param Collection<int, GraduateResult> $rows */
    private function stats(Collection $rows): array
    {
        $tcu = 0;
        $tgp = 0;
        foreach ($rows as $r) {
            if (! $r->waived && ! $r->exempted && $r->grade !== null && $r->flag !== 'withheld') {
                $tcu += $r->units;
                $tgp += $r->units * $r->grade_point;
            }
        }

        return ['cgpa' => $tcu ? round($tgp / $tcu, 2) : 0.0, 'passed_units' => $this->passedUnits($rows)];
    }

    /** Credit units of distinct courses with a passing score. */
    private function passedUnits(Collection $rows): int
    {
        $pass = config('portal.pass_mark');

        return (int) $rows->filter(fn ($r) => ! $r->waived && ! $r->exempted && $r->grade !== null && $r->flag !== 'withheld' && $r->total >= $pass)
            ->unique('course_code')->sum('units');
    }

    // ---- Plumbing ----

    private function create(Graduate $graduate, User $by, string $reason, array $data): GraduateAmendment
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Give the reason for the correction.']);
        }
        $amendment = GraduateAmendment::create($data + ['graduate_id' => $graduate->id, 'reason' => $reason, 'requested_by' => $by->id]);
        AuditLog::record('graduate.correction_requested', ['amendment_id' => $amendment->id, 'graduate_id' => $graduate->id]);

        User::where('role', User::ROLE_PRINCIPAL_ADMIN)->where('is_active', true)->where('id', '!=', $by->id)->get()
            ->each(fn ($hod) => $hod->notify(new PortalMessage('Graduate record correction to approve', "A correction to {$graduate->matric} is waiting for your decision.")));

        return $amendment;
    }

    private function requireRequested(GraduateAmendment $amendment): void
    {
        if ($amendment->status !== 'requested') {
            throw ValidationException::withMessages(['correction' => 'This correction has already been decided.']);
        }
    }

    private function notify(GraduateAmendment $amendment, string $title, string $message): void
    {
        $amendment->requester?->notify(new PortalMessage($title, $message));
    }

    private function f(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }
}
