<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ManageUserController extends Controller
{
    function index()
    {
        $user = User::with('role')->get();

        if ($user->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'User belum ditambahkan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    public function show($id)
    {
        $user = User::with('role')->find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|string|exists:role,role_name',
        ]);

        // Cari role berdasarkan nama
        $role = Role::where('role_name', $validated['role'])->first();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role_id' => $role->id,
        ]);

        // Load role biar response rapi
        $user->load('role');

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|string|exists:role,role_name',
        ]);

        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Update role jika dikirim
        if (isset($validated['role'])) {
            $role = Role::where('role_name', $validated['role'])->first();
            $user->role_id = $role->id;
        }

        // Update field lain jika ada
        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }

        if (isset($validated['password'])) {
            $user->password = bcrypt($validated['password']);
        }

        $user->save();
        $user->load('role');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->delete();
        $user->load('role');

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    public function showRoles()
    {
        $roles = Role::all();

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    public function showAdmin()
    {
        $role = Role::where('role_name', "admin")->first();

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => 'Role admin belum dibuat di tabel role'
            ], 404);
        }

        $users = User::with('role', 'admin')->where('role_id', $role->id)->get();

        return response()->json([
            'success' => true,
            'message' => 'Admin users retrieved successfully',
            'data' => $users
        ]);
    }

    public function showUser() {
        $role = Role::where('role_name', "user")->first();

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => 'Role user belum dibuat di tabel role'
            ], 404);
        }

        $users = User::with('role')->where('role_id', $role->id)->get();

        return response()->json([
            'success' => true,
            'message' => 'User users retrieved successfully',
            'data' => $users
        ]);
    }

    public function resetPassword($id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // OPTIONAL: proteksi super admin
        if ($user->id === 1) {
            return response()->json([
                'success' => false,
                'message' => 'Super admin password tidak dapat direset'
            ], 403);
        }

        // Password default
        $defaultPassword = 'password123';

        $user->password = Hash::make($defaultPassword);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil direset',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'default_password' => $defaultPassword // ❗ opsional, FE bisa tampilkan
            ]
        ]);
    }
}
