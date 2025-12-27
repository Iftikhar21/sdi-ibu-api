<?php

namespace App\Http\Controllers;

use App\Models\VisionMision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Http\Request;

class VisionMisionController extends Controller
{   
    function index() 
    {
        $visionMision = VisionMision::all();

        if ($visionMision->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Vision & Mission belum ditambahkan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $visionMision
        ]);
    }

    public function show($id)
    {
        $visionMision = VisionMision::all()->find($id);

        if (! $visionMision) {
            return response()->json([
                'success' => false,
                'message' => 'Vision & Mission not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $visionMision
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'vision' => 'nullable|string',
            'missions' => 'nullable|array',
            'missions.*' => 'string',
        ]);

        $visionMision = VisionMision::create($validated);

        return response()->json([
            'message' => 'Vision & Mission created successfully',
            'success' => true,
            'data' => $visionMision
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $visionMision = VisionMision::all()->find($id);

        if (! $visionMision) {
            return response()->json([
                'success' => false,
                'message' => 'Vision & Mission not found'
            ], 404);
        }

        $validated = $request->validate([
            'vision' => 'sometimes|nullable|string',
            'missions' => 'sometimes|nullable|array',
            'missions.*' => 'string',
        ]);

        $visionMision->update($validated);

        return response()->json([
            'message' => 'Vision & Mission updated successfully',
            'success' => true,
            'data' => $visionMision
        ]);
    }

    public function destroy($id)
    {
        $visionMision = VisionMision::all()->find($id);

        if (! $visionMision) {
            return response()->json([
                'success' => false,
                'message' => 'Vision & Mission not found'
            ], 404);
        }

        $visionMision->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vision & Mission deleted successfully'
        ]);
    }
}
