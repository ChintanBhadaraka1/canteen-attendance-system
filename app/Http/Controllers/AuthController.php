<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $validation = [];

    public function index()
    {
        return view('Auth.login');
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput($request->except('password'))
                ->with('error', 'Please fix the validation errors and try again.');
        }

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            return redirect()->route('dashboard')
                ->with('success', 'Login successful! Welcome back.');
        }


        return redirect()->back()->with('error', 'Invalid credentials!');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        // Invalidate the user's session
        $request->session()->invalidate();

        // Regenerate the CSRF token to prevent session fixation
        $request->session()->regenerateToken();

        // Redirect to the login page or home page after logout
        return redirect()->route('login')->with('success', 'You have been logged out successfully.');
    }
}
