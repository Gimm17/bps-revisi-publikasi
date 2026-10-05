<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt([...$request->safe()->only(['email', 'password']), 'is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Email atau password tidak cocok, atau akun belum aktif.']);
        }
        $request->session()->regenerate();
        AuditLogger::record('user.logged_in');

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
