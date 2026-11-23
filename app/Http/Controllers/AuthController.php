<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function index()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);
        unset($credentials['remember']);

        $key = 'login|' . Str::lower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();
        $intended = $request->session()->pull('url.intended');

        if (!$user->isAdmin()) {
            return redirect()->to($this->homeFor($user));
        }

        $request->session()->flash('success', 'Login successful! Welcome back.');

        return redirect()->to($intended ?? $this->homeFor($user));
    }

    public function showRegister()
    {
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:30'],
            'last_name' => ['nullable', 'string', 'min:2', 'max:30'],
            'collage_name' => ['required', 'string', 'min:2', 'max:30'],
            'email' => ['required', 'email', 'regex:/^[\w\.\-]+@([\w\-]+\.)+[\w\-]{2,4}$/', 'max:255', 'unique:users,email'],
            'phone_number' => ['required', 'regex:/^[0-9]{10}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], attributes: ['collage_name' => 'college']);

        $user = User::create(['role' => 'user'] + $data);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        return redirect()->to($this->homeFor($user))
            ->with('success', 'Registered. Collect your canteen ID at the counter.');
    }

    public function account(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->to($this->homeFor($user));
        }

        return Inertia::render('Account', [
            'student' => [
                'name' => $user->full_name,
                'collage_name' => $user->collage_name,
                'canteen_id' => $user->canteen_id,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        // Invalidate the user's session
        $request->session()->invalidate();

        // Regenerate the CSRF token to prevent session fixation
        $request->session()->regenerateToken();

        $request->session()->flash('success', 'You have been logged out successfully.');

        // A full page load, so the Back button cannot bring private pages back from memory.
        return Inertia::location(route('login'));
    }

    private function homeFor(User $user): string
    {
        return route($user->isAdmin() ? 'dashboard' : 'account');
    }
}
