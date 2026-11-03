<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Graduate;
use App\Models\GraduateAmendment;
use App\Services\GraduateAmendmentService;
use Illuminate\Http\Request;

/** The permanent graduate register. Read-only: changes go through approved corrections. */
class GraduateController extends Controller
{
    public function __construct(private GraduateAmendmentService $amendments) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $year = $request->integer('year');

        // Search and filter on the original values and on approved corrections (a corrected name must be findable).
        $amended = fn (string $field, string $op, $value) => GraduateAmendment::approved()->where('field', $field)->where('new_value->value', $op, $value)->select('graduate_id');

        $graduates = Graduate::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")->orWhere('matric', 'like', "%{$q}%")
                ->orWhereIn('id', $amended('name', 'like', "%{$q}%"))->orWhereIn('id', $amended('matric', 'like', "%{$q}%"))))
            ->when($year, fn ($query) => $query->where(fn ($w) => $w->where('graduation_year', $year)->orWhereIn('id', $amended('graduation_year', '=', $year))))
            ->orderByDesc('graduation_year')->orderBy('name')->paginate(30)->withQueryString();

        // Show every row as the record stands today.
        $graduates->setCollection($graduates->getCollection()->map(fn (Graduate $g) => $this->amendments->effective($g)));

        return view('admin.graduates.index', compact('graduates'));
    }

    public function show(Request $request, Graduate $graduate)
    {
        $effective = $graduate->effective();

        return view('admin.graduates.show', [
            'graduate' => $effective,
            'history' => GraduateAmendment::with(['requester', 'decider', 'result'])->where('graduate_id', $graduate->id)->orderByDesc('id')->get(),
            'fields' => GraduateAmendmentService::FIELDS,
            'isHod' => $request->user()->isPrincipalAdmin(),
        ]);
    }
}
