<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Desk\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeskAuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (app(Settings::class)->masterPassword() === '' || session()->get('desk_authed', false)) {
            return redirect('/');
        }

        return view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|string|max:200']);

        $master = app(Settings::class)->masterPassword();
        if ($master !== '' && hash_equals($master, (string) $request->input('password'))) {
            $request->session()->regenerate();
            $request->session()->put('desk_authed', true);

            return redirect('/');
        }

        return back()->withErrors(['password' => 'Wrong password.']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('desk_authed');

        return redirect('/login');
    }
}
