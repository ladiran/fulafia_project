<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Specialization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HoD only. Areas are deactivated, never deleted, so existing student records stay intact. */
class SpecializationController extends Controller
{
    public function index()
    {
        return view('admin.specializations', ['areas' => Specialization::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:specializations,name']]);
        $area = Specialization::create($data);
        AuditLog::record('specialization.added', ['name' => $area->name]);

        return back()->with('status', "{$area->name} added.");
    }

    public function update(Request $request, Specialization $specialization): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('specializations', 'name')->ignore($specialization->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $specialization->update(['name' => $data['name'], 'is_active' => $request->boolean('is_active')]);
        AuditLog::record('specialization.updated', ['id' => $specialization->id, 'name' => $data['name'], 'active' => $specialization->is_active]);

        return back()->with('status', 'Specialization updated.');
    }
}
