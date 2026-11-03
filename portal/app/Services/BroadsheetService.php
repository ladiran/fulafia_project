<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Graduate;
use App\Models\Result;
use Illuminate\Support\Collection;

/**
 * Data for the Senate-format level broadsheet: one level, one semester of one session, every student with
 * their score and grade point in each course, semester and cumulative totals, and the remarks column
 * (PASS / RPT: courses to repeat / TAKE: courses not registered). Only PUBLISHED results are used.
 */
class BroadsheetService
{
    public function __construct(private GraduationService $graduation, private StandingService $standing) {}

    /** @return array<string, mixed> */
    public function build(AcademicSession $session, int $semester, int $level): array
    {
        $registrations = CourseRegistration::where('academic_session_id', $session->id)->where('semester', $semester)
            ->where('status', CourseRegistration::APPROVED)->with(['course', 'student.user'])->get()
            ->filter(fn ($r) => ($r->level_at_registration ?? $r->student->current_level) === $level);

        // Courses whose sheet is not published yet cannot appear: warn instead of printing a wrong broadsheet.
        $offerings = CourseOffering::where('academic_session_id', $session->id)->whereIn('course_id', $registrations->pluck('course_id')->unique())
            ->get()->keyBy('course_id');
        $unpublished = $registrations->pluck('course')->unique('id')
            ->filter(fn ($c) => ($offerings->get($c->id)?->status ?? 'draft') !== CourseOffering::PUBLISHED)
            ->map(fn ($c) => $c->code)->sort()->values()->all();
        $registrations = $registrations->filter(fn ($r) => ($offerings->get($r->course_id)?->status ?? 'draft') === CourseOffering::PUBLISHED);

        $students = $registrations->pluck('student')->unique('id')->sortBy(fn ($s) => $s->user->identifier, SORT_NATURAL)->values();
        $courses = $this->columns($registrations, $level);

        $history = $this->history($students->pluck('id')->all());
        $graduates = $level === 400 && $semester === 2
            ? Graduate::where('graduation_session', $session->name)->get()->keyBy('student_profile_id')
            : collect();
        $threshold = config('portal.probation_cgpa');

        $rows = [];
        $summary = ['pass' => 0, 'repeat' => 0, 'unregistered' => 0, 'both' => 0, 'probation' => 0, 'withdraw' => 0];
        $classes = [];
        $hasPrevious = false;

        foreach ($students as $i => $student) {
            $mine = $registrations->where('student_profile_id', $student->id);
            $cells = [];
            $cur = ['tcr' => 0, 'tce' => 0, 'tgp' => 0];
            $registeredIds = [];
            foreach ($mine as $reg) {
                $result = $history['results'][$reg->id] ?? null;
                $cells[$reg->course_id] = $this->cell($result);
                $registeredIds[] = $reg->course_id;
                if ($result && $result->grade !== null && $result->flag !== 'withheld') {
                    $cur['tcr'] += $reg->course->units;
                    $cur['tgp'] += $reg->course->units * $result->grade_point;
                    $cur['tce'] += $result->isPassed() ? $reg->course->units : 0;
                }
            }

            $b = $this->cumulative($history['byStudent'][$student->id] ?? [], $session->name, $semester);
            $prevCgpa = $b['before_sem']['tcr'] ? round($b['before_sem']['tgp'] / $b['before_sem']['tcr'], 2) : null;
            $hasPrevious = $hasPrevious || $b['before_sem']['tcr'] > 0;

            $allPassed = $b['passed_through_now'];
            $rpt = collect($b['failed_unresolved'])->sort()->values()->all();
            $take = $this->toTake($student, $semester, $level, $allPassed, $registeredIds, $rpt);

            $cumTcr = $b['before_sem']['tcr'] + $cur['tcr'];
            $cumTgp = $b['before_sem']['tgp'] + $cur['tgp'];
            $cgpa = $cumTcr ? round($cumTgp / $cumTcr, 2) : null;
            $sessionStartCgpa = $b['before_session']['tcr'] ? round($b['before_session']['tgp'] / $b['before_session']['tcr'], 2) : null;

            [$remark, $category] = $this->remark($rpt, $take, $semester === 2 ? $cgpa : null, $sessionStartCgpa, $threshold);
            if ($graduate = $graduates->get($student->id)) {
                $effective = $graduate->effective(); // the class of degree as corrected
                $remark = 'PASS ('.$effective->class_of_degree.')';
                $category = 'pass';
                $classes[$effective->class_of_degree] = ($classes[$effective->class_of_degree] ?? 0) + 1;
            }
            $summary[$category]++;

            $rows[] = [
                'sn' => $i + 1, 'matric' => $student->user->identifier, 'cells' => $cells,
                'prev' => ['ctcr' => $b['before_sem']['tcr'], 'ctce' => $b['before_sem']['tce'], 'ctgp' => $b['before_sem']['tgp'], 'cgpa' => $prevCgpa],
                'cur' => $cur + ['gpa' => $cur['tcr'] ? round($cur['tgp'] / $cur['tcr'], 2) : null],
                'cum' => ['ctcr' => $cumTcr, 'ctce' => $b['tce_through_now'], 'ctgp' => $cumTgp, 'cgpa' => $cgpa],
                'remark' => $remark,
            ];
        }

        return [
            'session' => $session->name,
            'semester' => $semester,
            'semester_label' => $semester === 1 ? 'FIRST' : 'SECOND',
            'level' => $level,
            'courses' => $courses,
            'rows' => $rows,
            'has_previous' => $hasPrevious,
            'summary' => $summary + ['total' => count($rows)],
            'classes' => $level === 400 && $semester === 2 ? $classes : null,
            'unpublished' => $unpublished,
            'width' => 66 + count($courses) * 26 + ($hasPrevious ? 136 : 68) + 130,
        ];
    }

    /** Course columns: this level's courses first, then others (repeats and carry-overs), each by code. */
    private function columns(Collection $registrations, int $level): array
    {
        return $registrations->pluck('course')->unique('id')
            ->sortBy(fn ($c) => ($c->level === $level ? '0' : '1').sprintf('%03d', $c->level).$c->code)->values()
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'units' => $c->units, 'elective' => $c->type === 'E'])->all();
    }

    /** @return array{score: string, gp: ?int} */
    private function cell(?Result $r): array
    {
        return match (true) {
            $r === null => ['score' => '', 'gp' => null],
            $r->flag === 'absent' => ['score' => 'ABS', 'gp' => 0],
            $r->flag === 'incomplete' => ['score' => 'INC', 'gp' => null],
            $r->flag === 'withheld' => ['score' => 'WH', 'gp' => null],
            $r->total === null => ['score' => '', 'gp' => null],
            default => ['score' => rtrim(rtrim(number_format($r->total, 2, '.', ''), '0'), '.'), 'gp' => $r->grade_point],
        };
    }

    /**
     * Every approved registration of these students with its published result.
     *
     * @param  array<int, int>  $studentIds
     * @return array{byStudent: array<int, array<int, array>>, results: array<int, Result>}
     */
    private function history(array $studentIds): array
    {
        $regs = CourseRegistration::whereIn('student_profile_id', $studentIds)->where('status', CourseRegistration::APPROVED)
            ->with(['course', 'session'])->get();
        $results = Result::whereIn('course_registration_id', $regs->pluck('id'))
            ->whereHas('offering', fn ($q) => $q->where('status', CourseOffering::PUBLISHED))->get()->keyBy('course_registration_id');

        $by = [];
        foreach ($regs as $reg) {
            $by[$reg->student_profile_id][] = [
                'session' => $reg->session->name, 'semester' => $reg->semester, 'course_id' => $reg->course_id,
                'code' => $reg->course->code, 'units' => $reg->course->units, 'result' => $results->get($reg->id),
            ];
        }

        return ['byStudent' => $by, 'results' => $results->all()];
    }

    /**
     * Totals before this session, before this semester, and the courses passed / still failed up to and
     * including this semester. Credits earned count each course once; credits registered count every attempt.
     */
    private function cumulative(array $entries, string $sessionName, int $semester): array
    {
        $zero = ['tcr' => 0, 'tce' => 0, 'tgp' => 0];
        $beforeSession = $zero;
        $beforeSem = $zero;
        $passedBeforeSem = [];
        $passedBeforeSession = [];
        $passedNow = [];
        $failedNow = [];
        $unitsOf = [];

        usort($entries, fn ($a, $b) => [$a['session'], $a['semester']] <=> [$b['session'], $b['semester']]);

        foreach ($entries as $e) {
            $r = $e['result'];
            if (! $r || $r->grade === null || $r->flag === 'withheld') {
                continue;
            }
            $isBeforeSession = $e['session'] < $sessionName;
            $isBeforeSem = $isBeforeSession || ($e['session'] === $sessionName && $e['semester'] < $semester);
            $isUpToNow = $isBeforeSem || ($e['session'] === $sessionName && $e['semester'] === $semester);
            $unitsOf[$e['course_id']] = $e['units'];

            if ($isBeforeSession) {
                $beforeSession['tcr'] += $e['units'];
                $beforeSession['tgp'] += $e['units'] * $r->grade_point;
            }
            if ($isBeforeSem) {
                $beforeSem['tcr'] += $e['units'];
                $beforeSem['tgp'] += $e['units'] * $r->grade_point;
            }
            if ($isUpToNow) {
                if ($r->isPassed()) {
                    $passedNow[$e['course_id']] = true;
                    unset($failedNow[$e['course_id']]);
                } elseif (! isset($passedNow[$e['course_id']])) {
                    $failedNow[$e['course_id']] = $e['code'];
                }
            }
            if ($isBeforeSem && $r->isPassed()) {
                $passedBeforeSem[$e['course_id']] = true;
            }
            if ($isBeforeSession && $r->isPassed()) {
                $passedBeforeSession[$e['course_id']] = true;
            }
        }
        $earned = fn (array $set) => array_sum(array_map(fn ($id) => $unitsOf[$id] ?? 0, array_keys($set)));
        $beforeSem['tce'] = $earned($passedBeforeSem);
        $beforeSession['tce'] = $earned($passedBeforeSession);

        return [
            'before_session' => $beforeSession,
            'before_sem' => $beforeSem,
            'passed_through_now' => array_keys($passedNow),
            'tce_through_now' => $earned($passedNow),
            'failed_unresolved' => array_values($failedNow),
        ];
    }

    /** Required core courses of this semester, up to this level, not yet passed and not registered now. */
    private function toTake($student, int $semester, int $level, array $passedIds, array $registeredIds, array $rptCodes): array
    {
        $exempt = $this->standing->exemptedCourseIds($student);

        return $this->graduation->requiredCoreCourses($student)
            ->filter(fn ($c) => $c->semester === $semester && $c->level <= $level)
            ->reject(fn ($c) => in_array($c->id, $passedIds, true) || in_array($c->id, $registeredIds, true) || $exempt->contains($c->id) || in_array($c->code, $rptCodes, true))
            ->map(fn ($c) => $c->code)->sort()->values()->all();
    }

    /** @return array{0: string, 1: string} [remark text, summary category] */
    private function remark(array $rpt, array $take, ?float $cgpaAtSessionEnd, ?float $cgpaAtSessionStart, float $threshold): array
    {
        // Course codes are written without the space, as on the department's printed broadsheets (PHY111).
        $list = fn (array $codes) => implode(' ', array_map(fn ($c) => str_replace(' ', '', $c), $codes));

        if ($cgpaAtSessionEnd !== null && $cgpaAtSessionEnd < $threshold) {
            return ($cgpaAtSessionStart !== null && $cgpaAtSessionStart < $threshold)
                ? ['TO WITHDRAW'.($rpt ? ' · RPT: '.$list($rpt) : ''), 'withdraw']
                : ['PROBATION'.($rpt ? ' · RPT: '.$list($rpt) : '').($take ? ' · TAKE: '.$list($take) : ''), 'probation'];
        }

        $parts = [];
        if ($rpt) {
            $parts[] = 'RPT: '.$list($rpt);
        }
        if ($take) {
            $parts[] = 'TAKE: '.$list($take);
        }

        return match (true) {
            ! $rpt && ! $take => ['PASS', 'pass'],
            $rpt && $take => [implode(' · ', $parts), 'both'],
            (bool) $rpt => [implode(' · ', $parts), 'repeat'], // match(true) compares strictly, so it must be a real boolean
            default => [implode(' · ', $parts), 'unregistered'],
        };
    }
}
