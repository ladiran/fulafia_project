<?php

namespace App\Services;

use App\Models\Graduate;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * Builds the data for the departmental transcript PDF. It is a working document for the department,
 * not an official transcript: a graduate's copy comes from the frozen graduate record, anyone else's
 * from their published results so far.
 */
class TranscriptService
{
    public function __construct(private GpaService $gpa) {}

    public function forStudent(StudentProfile $student): array
    {
        $student->load(['user', 'specialization', 'entrySession', 'graduate.results', 'nameHistory']);

        return $student->graduate ? $this->fromGraduate($student, $student->graduate->effective()) : $this->fromResults($student);
    }

    public function forGraduate(Graduate $graduate): array
    {
        $student = StudentProfile::with(['user', 'specialization', 'entrySession', 'nameHistory'])->find($graduate->student_profile_id);

        // The record as corrected; the transcript lists the corrections that were applied.
        return $this->fromGraduate($student, $graduate->effective());
    }

    /** Interim: published results to date. */
    private function fromResults(StudentProfile $student): array
    {
        $summary = $this->gpa->summary($student);

        $semesters = collect($summary['semesters'])->map(fn ($sem) => [
            'label' => $sem['session'].' · '.($sem['semester'] == 1 ? 'First' : 'Second').' semester',
            'rows' => $sem['results']->map(function ($r) {
                $c = $r->registration->course;
                $graded = $r->isGraded() && $r->flag !== 'withheld';

                return [
                    'code' => $c->code, 'title' => $c->title, 'type' => $c->type, 'units' => $c->units,
                    'score' => $graded ? $this->score($r->total) : null,
                    'grade' => $graded ? $r->grade : null,
                    'gp' => $graded ? $r->grade_point : null,
                    'note' => $r->flag === 'withheld' ? 'Withheld' : (! $r->isGraded() ? ucfirst($r->flag ?? 'pending') : ($r->flag === 'absent' ? 'Absent' : null)),
                ];
            })->all(),
            'tcu' => $sem['tcu'], 'tgp' => $sem['tgp'], 'gpa' => $sem['gpa'], 'cgpa' => $sem['cgpa'],
        ])->all();

        return $this->base($student) + [
            'kind' => 'interim',
            'semesters' => $semesters,
            'award' => null,
            'totals' => ['cgpa' => $summary['cgpa'], 'tcu' => $summary['tcu'], 'credits_passed' => $summary['credits_passed']],
        ];
    }

    /** Graduate: the frozen record, so later changes to live data never alter it. */
    private function fromGraduate(StudentProfile $student, Graduate $graduate): array
    {
        $cumTcu = 0;
        $cumTgp = 0;

        $semesters = $graduate->results->groupBy(fn ($r) => $r->session.'|'.$r->semester)
            ->sortKeys()
            ->map(function (Collection $rows, string $key) use (&$cumTcu, &$cumTgp) {
                [$session, $semester] = explode('|', $key);
                $tcu = 0;
                $tgp = 0;
                $out = [];
                foreach ($rows->sortBy('course_code') as $r) {
                    $graded = ! $r->waived && ! $r->exempted && $r->grade !== null && $r->flag !== 'withheld';
                    if ($graded) {
                        $tcu += $r->units;
                        $tgp += $r->units * $r->grade_point;
                    }
                    $out[] = [
                        'code' => $r->course_code, 'title' => $r->course_title, 'type' => $r->course_type, 'units' => $r->units,
                        'score' => $graded ? $this->score($r->total) : null,
                        'grade' => $graded ? $r->grade : null,
                        'gp' => $graded ? $r->grade_point : null,
                        'note' => match (true) {
                            (bool) $r->exempted => 'Exempted (transfer credit)',
                            (bool) $r->waived => 'Waived by the HoD',
                            $r->flag === 'withheld' => 'Withheld',
                            $r->grade === null => ucfirst($r->flag ?? 'n/a'),
                            $r->flag === 'absent' => 'Absent',
                            default => null,
                        },
                    ];
                }
                $cumTcu += $tcu;
                $cumTgp += $tgp;

                return [
                    'label' => $session.' · '.($semester == 1 ? 'First' : 'Second').' semester',
                    'rows' => $out, 'tcu' => $tcu, 'tgp' => $tgp,
                    'gpa' => $tcu ? round($tgp / $tcu, 2) : null,
                    'cgpa' => $cumTcu ? round($cumTgp / $cumTcu, 2) : null,
                ];
            })->values()->all();

        return $this->base($student, $graduate) + [
            'kind' => 'graduate',
            'corrections' => $graduate->amendments->map(fn ($a) => $a->decided_at->format('j M Y').': '.$a->summary().' (reason: '.$a->reason.')')->all(),
            'semesters' => $semesters,
            'award' => [
                'degree' => $graduate->degree, 'class' => $graduate->class_of_degree, 'cgpa' => $graduate->cgpa,
                'session' => $graduate->graduation_session, 'year' => $graduate->graduation_year,
                'credits_registered' => $graduate->credits_registered, 'credits_passed' => $graduate->credits_passed,
            ],
            'totals' => ['cgpa' => $graduate->cgpa, 'tcu' => $cumTcu, 'credits_passed' => $graduate->credits_passed],
        ];
    }

    private function base(StudentProfile $student, ?Graduate $graduate = null): array
    {
        return [
            'person' => [
                // A graduate's name is the one on the permanent record.
                'name' => $graduate?->name ?? $student->user->name,
                'matric' => $graduate?->matric ?? $student->user->identifier,
                'gender' => ucfirst($graduate?->gender ?? $student->gender),
                'admission' => StudentProfile::ENTRY_MODES[$student->entry_mode] ?? $student->entry_mode,
                'entry_session' => $graduate?->entry_session ?? $student->entrySession?->name,
                'level' => $student->current_level.'L',
                'status' => ucfirst($student->status),
                'specialization' => $graduate?->specialization ?? $student->specialization?->name,
                'formerly' => $student->nameHistory->pluck('previous_name')->unique()->values()->all(),
            ],
        ];
    }

    private function score(?float $total): ?string
    {
        return $total === null ? null : rtrim(rtrim(number_format($total, 2), '0'), '.');
    }
}
