<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * $roles bisa berisi beberapa role, mis. `role:admin,guru`.
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        // 1. Belum login (fallback, biasanya auth:sanctum sudah handle)
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum login',
            ], 401);
        }

        // 2. User tidak punya role
        if (! $user->role) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak memiliki role',
            ], 403);
        }

        // 3. Role tidak sesuai
        if (! in_array($user->role->role_name, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        return $next($request);
    }
}
