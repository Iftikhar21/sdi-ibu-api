<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRegistrationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $status = $request->get('status');
        $search = $request->get('search');

        $query = StudentRegistration::with(['user' => function ($query) {
            $query->select('id', 'name', 'email');
        }])
            ->latest();

        // Filter by status
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        // Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nickname', 'like', "%{$search}%")
                    ->orWhere('father_name', 'like', "%{$search}%")
                    ->orWhere('mother_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $registrations = $query->paginate($perPage);

        // Add URLs
        $registrations->getCollection()->transform(function ($item) {
            $item->photo_url = $item->photo ? asset('storage/' . $item->photo) : null;
            $item->birth_certificate_url = $item->birth_certificate ? asset('storage/' . $item->birth_certificate) : null;
            $item->family_card_url = $item->family_card ? asset('storage/' . $item->family_card) : null;
            $item->payment_proof_url = $item->payment_proof ? asset('storage/' . $item->payment_proof) : null;
            return $item;
        });

        // Get statistics
        $stats = [
            'total' => StudentRegistration::count(),
            'submitted' => StudentRegistration::where('status', 'submitted')->count(),
            'review' => StudentRegistration::where('status', 'review')->count(),
            'approved' => StudentRegistration::where('status', 'approved')->count(),
            'rejected' => StudentRegistration::where('status', 'rejected')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $registrations,
            'stats' => $stats,
            'meta' => [
                'total' => $registrations->total(),
                'per_page' => $registrations->perPage(),
                'current_page' => $registrations->currentPage(),
                'last_page' => $registrations->lastPage(),
            ]
        ]);
    }

    public function show($id)
    {
        $registration = StudentRegistration::with(['user' => function ($query) {
            $query->select('id', 'name', 'email', 'created_at');
        }])->findOrFail($id);

        $registration->photo_url = $registration->photo ? asset('storage/' . $registration->photo) : null;
        $registration->birth_certificate_url = $registration->birth_certificate ? asset('storage/' . $registration->birth_certificate) : null;
        $registration->family_card_url = $registration->family_card ? asset('storage/' . $registration->family_card) : null;
        $registration->payment_proof_url = $registration->payment_proof ? asset('storage/' . $registration->payment_proof) : null;

        return response()->json([
            'success' => true,
            'data' => $registration
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:submitted,review,approved,rejected',
            'notes' => 'nullable|string|max:500'
        ]);

        $registration = StudentRegistration::findOrFail($id);

        $oldStatus = $registration->status;
        $registration->status = $request->status;
        $registration->notes = $request->notes;
        $registration->save();

        // Log activity
        // activity('registration')
        //     ->performedOn($registration)
        //     ->causedBy(auth()->user())
        //     ->withProperties([
        //         'old_status' => $oldStatus,
        //         'new_status' => $request->status,
        //         'notes' => $request->notes
        //     ])
        //     ->log('Status pendaftaran diubah');

        $registration->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Status pendaftaran berhasil diperbarui',
            'data' => $registration
        ]);
    }

    public function statistics()
    {
        $totalByMonth = StudentRegistration::select(
            DB::raw('YEAR(created_at) as year'),
            DB::raw('MONTH(created_at) as month'),
            DB::raw('COUNT(*) as total')
        )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        $byGender = StudentRegistration::select('gender', DB::raw('COUNT(*) as total'))
            ->groupBy('gender')
            ->get();

        $byStatus = StudentRegistration::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'by_month' => $totalByMonth,
                'by_gender' => $byGender,
                'by_status' => $byStatus
            ]
        ]);
    }
}
