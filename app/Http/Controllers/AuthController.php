<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = DB::table('users')->where('email', $credentials['email'])->first();
        $passwordHash = $user->password_hash ?? $user->password ?? null;

        if (! $user || ! $passwordHash || ! Hash::check($credentials['password'], $passwordHash)) {
            return response()->json([
                'code' => 'invalid_credentials',
                'success' => false,
                'message' => 'The provided credentials do not match our records.',
            ], 422);
        }

        $role = $user->role ?? null;
        if (! $role && isset($user->role_id)) {
            $role = DB::table('roles')->where('id', $user->role_id)->value('role_key');
        }

        if (isset($user->is_active) && ! $user->is_active) {
            return response()->json([
                'code' => 'inactive_account',
                'success' => false,
                'message' => 'This account is not active.',
            ], 403);
        }

        if (! in_array($role, ['admin', 'teacher', 'secretary'], true)) {
            return response()->json([
                'code' => 'role_not_allowed',
                'success' => false,
                'message' => 'This account is not allowed to access the dashboard.',
            ], 403);
        }

        Auth::loginUsingId($user->id, $request->boolean('remember'));
        $request->session()->regenerate();

        // Relative URL so the browser stays on the host it used to log in
        // (localhost vs 127.0.0.1 keep separate session cookies).
        return response()->json(['success' => true, 'redirect' => route('dashboard', [], false)]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
