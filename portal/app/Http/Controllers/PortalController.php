<?php

namespace App\Http\Controllers;

use App\Models\LevelAdviserAppointment;
use App\Models\User;

class PortalController extends Controller
{
    public function dashboard()
    {
        return redirect()->route(auth()->user()->homeRoute());
    }

    public function admin()
    {
        $advisers = LevelAdviserAppointment::holding()->with('user')->orderBy('level')->get()->keyBy('level');

        return view('admin.dashboard', [
            'advisers' => $advisers,
            'staffCount' => User::where('role', User::ROLE_LECTURER)->count(),
            'studentCount' => User::where('role', User::ROLE_STUDENT)->count(),
        ]);
    }

    public function staff()
    {
        return view('portal.staff', ['appointment' => auth()->user()->currentAdviserAppointment()]);
    }

}
