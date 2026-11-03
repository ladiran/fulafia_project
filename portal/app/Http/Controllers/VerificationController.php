<?php

namespace App\Http\Controllers;

use App\Models\Graduate;
use Illuminate\Http\Request;

/** Public page for employers and institutions to confirm a graduate with the code printed on their statement. */
class VerificationController extends Controller
{
    public function form(Request $request)
    {
        $code = strtoupper(trim((string) $request->query('code')));
        // Show the record as corrected, never the superseded original.
        $graduate = $code !== '' ? Graduate::where('verification_code', $code)->first()?->effective() : null;

        return view('public.verify', ['code' => $code, 'graduate' => $graduate]);
    }
}
