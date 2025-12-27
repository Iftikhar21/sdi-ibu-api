<?php

namespace App\Http\Controllers;

use App\Models\History;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    function index()
    {
        $history = History::all();

        if ($history->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'History belum ditambahkan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    public function show($id)
    {
        $history = History::all()->find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'History not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        $history = History::create($validated);

        return response()->json([
            'success' => true,
            'data' => $history
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $history = History::all()->find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'History not found'
            ], 404);
        }

        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        $history->update($validated);

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    public function destroy($id)
    {
        $history = History::all()->find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'History not found'
            ], 404);
        }

        $history->delete();

        return response()->json([
            'success' => true,
            'message' => 'History deleted successfully'
        ]);
    }
}
