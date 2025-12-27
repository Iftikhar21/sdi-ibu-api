<?php

namespace App\Http\Controllers;

use App\Models\UserDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function getProfile()
    {
        $user = Auth::user();

        // pastikan user punya role user
        if ($user->role->role_name !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. You are not an user.'
            ], 403);
        }

        $userDetail = UserDetail::where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'user_detail' => $userDetail
            ]
        ]);
    }

    /**
     * PUT /api/user/profile
     * Update profile user (phone)
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

        // USER DETAIL
        $userDetail = UserDetail::updateOrCreate(
            ['user_id' => $user->id],
            ['phone' => $validated['phone'] ?? null]
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user,
                'user_detail' => $userDetail
            ]
        ]);
    }
}
