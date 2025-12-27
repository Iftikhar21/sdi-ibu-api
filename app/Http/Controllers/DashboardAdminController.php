<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\StudentRegistration;
use App\Models\User;
use App\Models\News;
use App\Models\Program;

class DashboardAdminController extends Controller
{
    public function index()
    {
        // Statistik Pendaftaran
        $registrationStats = [
            'total' => StudentRegistration::count(),
            'today' => StudentRegistration::whereDate('created_at', today())->count(),
            'this_month' => StudentRegistration::whereMonth('created_at', date('m'))
                ->whereYear('created_at', date('Y'))
                ->count(),
            'submitted' => StudentRegistration::where('status', 'submitted')->count(),
            'review' => StudentRegistration::where('status', 'review')->count(),
            'approved' => StudentRegistration::where('status', 'approved')->count(),
            'rejected' => StudentRegistration::where('status', 'rejected')->count(),
        ];

        // Pendaftaran per bulan (6 bulan terakhir)
        $monthlyRegistrations = StudentRegistration::select(
            DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->month => $item->count];
            });

        // Pendaftaran berdasarkan status
        $statusDistribution = StudentRegistration::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->status => $item->count];
            });

        // Pendaftaran berdasarkan jenis kelamin
        $genderDistribution = StudentRegistration::select('gender', DB::raw('COUNT(*) as count'))
            ->groupBy('gender')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->gender => $item->count];
            });

        // Statistik User
        $userStats = [
            'total' => User::count(),
            'admin' => User::whereHas('role', function ($query) {
                $query->where('role_name', 'admin');
            })->count(),
            'user' => User::whereHas('role', function ($query) {
                $query->where('role_name', 'user');
            })->count(),
            'new_today' => User::whereDate('created_at', today())->count(),
            'new_this_month' => User::whereMonth('created_at', date('m'))
                ->whereYear('created_at', date('Y'))
                ->count(),
        ];

        // User baru per bulan (6 bulan terakhir)
        $monthlyUsers = User::select(
            DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->month => $item->count];
            });

        // Statistik Konten
        $contentStats = [
            'news_total' => News::count(),
            'news_published' => News::count(),
            'program_total' => Program::count(),
            'program_active' => Program::where('status', 'published')->count(),
        ];

        // Pendaftaran terbaru (10 terbaru)
        $latestRegistrations = StudentRegistration::with(['user' => function ($query) {
            $query->select('id', 'name', 'email');
        }])
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'full_name' => $item->full_name,
                    'nickname' => $item->nickname,
                    'status' => $item->status,
                    'created_at' => $item->created_at,
                    'user_name' => $item->user->name ?? 'N/A',
                ];
            });

        // User baru (10 terbaru)
        $latestUsers = User::with('role')
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'email' => $item->email,
                    'role' => $item->role->role_name,
                    'created_at' => $item->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'registration_stats' => $registrationStats,
                'user_stats' => $userStats,
                'content_stats' => $contentStats,
                'monthly_registrations' => $monthlyRegistrations,
                'monthly_users' => $monthlyUsers,
                'status_distribution' => $statusDistribution,
                'gender_distribution' => $genderDistribution,
                'latest_registrations' => $latestRegistrations,
                'latest_users' => $latestUsers,
            ]
        ]);
    }

    public function quickStats()
    {
        // Quick stats untuk widget
        return response()->json([
            'success' => true,
            'data' => [
                'total_registrations' => StudentRegistration::count(),
                'pending_review' => StudentRegistration::where('status', 'submitted')->count(),
                'total_users' => User::count(),
                'new_users_today' => User::whereDate('created_at', today())->count(),
                'total_news' => News::count(),
                'total_programs' => Program::count(),
            ]
        ]);
    }
}
