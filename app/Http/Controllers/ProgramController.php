<?php

namespace App\Http\Controllers;

use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProgramController extends Controller
{
    public function index()
    {
        $programs = Program::all();

        if ($programs->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Program belum ditambahkan'
            ], 404);
        }

        $programs->map(function ($program) {
            $program->thumbnail_url = $program->thumbnail
                ? asset('storage/' . $program->thumbnail)
                : asset('images/no-image.png');

            return $program;
        });

        return response()->json([
            'success' => true,
            'data' => $programs
        ]);
    }

    public function show($id)
    {
        $program = Program::find($id);

        if (! $program) {
            return response()->json([
                'success' => false,
                'message' => 'Program not found'
            ], 404);
        }

        $program->thumbnail_url = $program->thumbnail
            ? asset('storage/' . $program->thumbnail)
            : asset('images/no-image.png');

        return response()->json([
            'success' => true,
            'data' => $program
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required',
            'thumbnail'   => 'nullable|image|max:2048',
            'status'      => 'required|in:draft,published',
        ]);

        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')
                ->store('program/thumbnails', 'public');
        }

        $program = Program::create([
            'title'     => $validated['title'],
            'slug'      => Str::slug($validated['title']),
            'description'   => $validated['description'],
            'thumbnail' => $thumbnailPath,
            'status'    => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $program
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $program = Program::findOrFail($id);

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',  // ← ubah jadi sometimes
            'description' => 'sometimes',                  // ← ubah jadi sometimes
            'thumbnail'   => 'nullable|image|max:2048',
            'status'      => 'sometimes|in:draft,published', // ← ubah jadi sometimes
        ]);

        // Update hanya field yang ada di request
        if ($request->filled('title')) {
            $program->title = $request->title;
            $program->slug = Str::slug($request->title);
        }

        if ($request->filled('description')) {
            $program->description = $request->description;
        }

        if ($request->filled('status')) {
            $program->status = $request->status;
        }

        if ($request->hasFile('thumbnail')) {
            if ($program->thumbnail) {
                Storage::disk('public')->delete($program->thumbnail);
            }
            $program->thumbnail = $request->file('thumbnail')
                ->store('program/thumbnails', 'public');
        }

        $program->save();

        return response()->json([
            'success' => true,
            'data' => $program
        ]);
    }

    public function destroy($id)
    {
        $program = Program::findOrFail($id);

        if ($program->thumbnail) {
            Storage::disk('public')->delete($program->thumbnail);
        }

        $program->delete();

        return response()->json([
            'success' => true,
            'message' => 'Program deleted successfully'
        ]);
    }
}
