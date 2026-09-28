<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Graduation;
use App\Models\News;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ],
        ]);
    }

    /**
     * Rekap data sekolah untuk dashboard (monitoring saja, tanpa mengubah data).
     *
     * Semua angka yang berkaitan dengan tahun ajaran mengikuti filter
     * `academic_year_id`; bila tidak dikirim, dipakai tahun ajaran aktif.
     */
    public function schoolSummary(Request $request)
    {
        $academicYears = AcademicYear::ordered()->get(['id', 'name', 'is_active']);

        $academicYearId = $request->get('academic_year_id')
            ?: $academicYears->firstWhere('is_active', true)?->id
            ?: $academicYears->first()?->id;

        $academicYear = $academicYears->firstWhere('id', (int) $academicYearId);

        // --- Pendaftaran: dihitung dari status existing ---
        $registrationCounts = $academicYear
            ? StudentRegistration::where('academic_year_id', $academicYear->id)
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
            : collect();

        $registrations = [
            'total' => (int) $registrationCounts->sum(),
            'submitted' => (int) ($registrationCounts['submitted'] ?? 0),
            'review' => (int) ($registrationCounts['review'] ?? 0),
            'approved' => (int) ($registrationCounts['approved'] ?? 0),
            'rejected' => (int) ($registrationCounts['rejected'] ?? 0),
            // Data lama yang belum dikaitkan ke tahun ajaran mana pun
            'without_academic_year' => StudentRegistration::whereNull('academic_year_id')->count(),
        ];

        // --- Siswa: status siswa (tidak terikat tahun ajaran) ---
        $studentCounts = Student::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $students = [
            'total' => (int) $studentCounts->sum(),
            'active' => (int) ($studentCounts[Student::STATUS_ACTIVE] ?? 0),
            'inactive' => (int) ($studentCounts[Student::STATUS_INACTIVE] ?? 0),
            'graduated' => (int) ($studentCounts[Student::STATUS_GRADUATED] ?? 0),
        ];

        // --- Kelas + keterisian (dari penempatan kelas, bukan pendaftaran) ---
        $classrooms = $academicYear
            ? Classroom::where('academic_year_id', $academicYear->id)
                ->withCount('activePlacements')
                ->ordered()
                ->get()
            : collect();

        $classRows = $classrooms->map(fn (Classroom $classroom) => [
            'id' => $classroom->id,
            'display_name' => $classroom->display_name,
            'grade_level' => $classroom->grade_level,
            'quota' => $classroom->quota,
            'filled' => $classroom->filled_count,
            'available' => $classroom->available_count,
            'is_active' => $classroom->is_active,
        ])->values();

        $activeClassRows = $classRows->where('is_active', true);

        $classes = [
            'total' => $activeClassRows->count(),
            'capacity' => (int) $activeClassRows->sum('quota'),
            'filled' => (int) $activeClassRows->sum('filled'),
            'available' => (int) $activeClassRows->sum('available'),
            'rows' => $classRows->all(),
        ];

        // --- Siswa sudah menjadi Student tetapi belum punya kelas pada tahun ini ---
        $unplacedStudents = $academicYear
            ? Student::where('status', Student::STATUS_ACTIVE)
                ->whereHas('registration', function ($query) use ($academicYear) {
                    $query->where('academic_year_id', $academicYear->id);
                })
                ->whereDoesntHave('activePlacement', function ($query) use ($academicYear) {
                    $query->where('academic_year_id', $academicYear->id);
                })
                ->count()
            : 0;

        // --- Lulusan: dari tabel graduations (bukan pendaftaran) ---
        $graduatesByYear = Graduation::query()
            ->join('academic_years', 'academic_years.id', '=', 'graduations.graduation_year_id')
            ->selectRaw('academic_years.id as id, academic_years.name as name, COUNT(*) as total')
            ->groupBy('academic_years.id', 'academic_years.name', 'academic_years.start_date')
            ->orderByDesc('academic_years.start_date')
            ->orderByDesc('academic_years.id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'total' => (int) $row->total,
            ]);

        $graduates = [
            'total' => Graduation::count(),
            'this_year' => $academicYear
                ? Graduation::where('graduation_year_id', $academicYear->id)->count()
                : 0,
            'by_year' => $graduatesByYear,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'academic_years' => $academicYears,
                'academic_year' => $academicYear,
                'registrations' => $registrations,
                'students' => $students,
                'classes' => $classes,
                'unplaced_students' => $unplacedStudents,
                'graduates' => $graduates,
            ],
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
            ],
        ]);
    }
}
