<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function index()
    {
        $faqs = Faq::ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $faqs,
        ]);
    }

    public function show($id)
    {
        $faq = Faq::find($id);

        if (! $faq) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $faq,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $faq = Faq::create([
            'question' => $validated['question'],
            'answer' => $validated['answer'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'FAQ berhasil ditambahkan',
            'data' => $faq,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $faq = Faq::find($id);

        if (! $faq) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'question' => 'sometimes|required|string|max:255',
            'answer' => 'sometimes|required|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($request->has('question')) {
            $faq->question = $request->input('question');
        }

        if ($request->has('answer')) {
            $faq->answer = $request->input('answer');
        }

        if ($request->has('sort_order')) {
            $faq->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $faq->is_active = $request->boolean('is_active');
        }

        $faq->save();

        return response()->json([
            'success' => true,
            'message' => 'FAQ berhasil diperbarui',
            'data' => $faq,
        ]);
    }

    public function destroy($id)
    {
        $faq = Faq::find($id);

        if (! $faq) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ tidak ditemukan',
            ], 404);
        }

        $faq->delete();

        return response()->json([
            'success' => true,
            'message' => 'FAQ berhasil dihapus',
        ]);
    }
}
