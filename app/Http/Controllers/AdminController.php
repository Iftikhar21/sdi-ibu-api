<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    /**
     * GET /api/admin/profile
     * Ambil profile admin berdasarkan user login
     */
    public function getProfile()
    {
        $user = Auth::user();

        // pastikan user punya role admin
        if ($user->role->role_name !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. You are not an admin.'
            ], 403);
        }

        $admin = Admin::where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'admin' => $admin
            ]
        ]);
    }

    /**
     * PUT /api/admin/profile
     * Update profile admin (phone)
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'phone' => 'nullable|string|max:20',
        ]);

        // USER
        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }

        if ($request->filled('password')) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        // ADMIN
        $admin = Admin::updateOrCreate(
            ['user_id' => $user->id],
            ['phone' => $validated['phone'] ?? null]
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user,
                'admin' => $admin
            ]
        ]);
    }
}