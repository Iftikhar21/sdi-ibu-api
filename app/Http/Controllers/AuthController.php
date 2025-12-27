<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:6',
        ]);

        if (User::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'Email sudah terdaftar di database'
            ], 400);
        }

        $role = Role::where('role_name', 'user')->first();

        if (! $role) {
            return response()->json([
                'message' => 'Role user belum dibuat di tabel role'
            ], 400);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role_id' => $role->id,
        ]);

        // 🔥 load role
        $user->load('role');

        return response()->json([
            'message' => 'Registrasi berhasil!',
            'user' => $user
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::with('role')->where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Email tidak terdaftar.'
            ], 404);
        }

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password salah.'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil!',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json([
            'message' => 'Logout berhasil!'
        ]);
    }
}
