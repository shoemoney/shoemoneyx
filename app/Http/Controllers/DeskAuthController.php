<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Desk\DeskAuthThrottle;
use App\Desk\DeskLogin;
use App\Desk\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeskAuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (app(Settings::class)->masterPassword() === '' || DeskLogin::check($request->session())) {
            return redirect('/');
        }

        return view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|string|max:200']);

        if (DeskAuthThrottle::tooManyFailures($request)) {
            DeskAuthThrottle::lockout($request);
        }

        $master = app(Settings::class)->masterPassword();
        if ($master !== '' && hash_equals($master, (string) $request->input('password'))) {
            DeskLogin::grant($request->session());

            return redirect('/');
        }

        DeskAuthThrottle::recordFailure($request);

        return back()->withErrors(['password' => 'Wrong password.']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
