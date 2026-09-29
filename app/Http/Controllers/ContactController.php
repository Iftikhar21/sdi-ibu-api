<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContactController extends Controller
{
    private function extractMapSrc($iframe)
    {
        if (! $iframe) {
            return null;
        }

        // decode HTML entity dulu
        $iframe = html_entity_decode($iframe, ENT_QUOTES);

        if (preg_match('/src="([^"]+)"/', $iframe, $match)) {
            return $match[1];
        }

        return $iframe;
    }

    public function index()
    {
        $contact = Contact::with('socials')->latest()->first();

        if (! $contact) {
            return response()->json([
                'success' => false,
                'message' => 'Contact belum ditambahkan',
            ], 404);
        }

        $contact->logo_url = $contact->logo
            ? asset('storage/'.$contact->logo)
            : null;

        return response()->json([
            'success' => true,
            'data' => $contact,
        ]);
    }

    public function show($id)
    {
        $contact = Contact::with('socials')->find($id);

        if (! $contact) {
            return response()->json([
                'success' => false,
                'message' => 'Contact not found',
            ], 404);
        }

        $contact->logo_url = $contact->logo
            ? asset('storage/'.$contact->logo)
            : null;

        return response()->json([
            'success' => true,
            'data' => $contact,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'deskripsi' => 'required|nullable|string',
            'alamat' => 'required|nullable|string',
            'telepon' => ['required', 'nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]*$/'],
            'email' => 'required|nullable|email|max:255',
            'map_embed' => 'required|nullable|string',
            'socials' => 'nullable|array',
            'socials.*.platform' => 'required_with:socials|string',
            'socials.*.url' => 'required_with:socials|url',
        ], [
            'telepon.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, dan simbol + - ( ).',
        ]);

        // Upload logo
        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')
                ->store('contact/logos', 'public');
        }

        // Extract map embed src if iframe
        $mapEmbed = $validated['map_embed'] ?? null;
        if ($mapEmbed) {
            $mapEmbed = $this->extractMapSrc($mapEmbed);
        }

        $contact = Contact::create([
            'logo' => $logoPath,
            'deskripsi' => $validated['deskripsi'] ?? null,
            'alamat' => $validated['alamat'] ?? null,
            'telepon' => $validated['telepon'] ?? null,
            'email' => $validated['email'] ?? null,
            'map_embed' => $mapEmbed,
        ]);

        // Create socials if exists
        if ($request->filled('socials')) {
            foreach ($request->socials as $social) {
                if (! empty($social['platform']) && ! empty($social['url'])) {
                    $contact->socials()->create([
                        'platform' => $social['platform'],
                        'url' => $social['url'],
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Contact created successfully',
            'data' => $contact->load('socials'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        Log::info('=== UPDATE CONTACT ===');
        Log::info('Method: '.$request->method());
        Log::info('Content-Type: '.$request->header('Content-Type'));
        Log::info('All: ', $request->all());
        Log::info('Input deskripsi: '.$request->input('deskripsi', 'NOT FOUND'));
        Log::info('Input alamat: '.$request->input('alamat', 'NOT FOUND'));

        $contact = Contact::findOrFail($id);

        // Validasi minimal
        $request->validate([
            'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_logo' => 'sometimes|boolean',
            'email' => 'sometimes|nullable|email',
        ]);

        // LOGO
        if ($request->boolean('remove_logo') && ! $request->hasFile('logo')) {
            if ($contact->logo) {
                Storage::disk('public')->delete($contact->logo);
            }
            $contact->logo = null;
        } elseif ($request->hasFile('logo')) {
            if ($contact->logo) {
                Storage::disk('public')->delete($contact->logo);
            }
            $contact->logo = $request->file('logo')->store('contact/logos', 'public');
        }

        // UPDATE FIELD LAIN - handle empty strings
        // Gunakan has() untuk check jika field dikirim
        if ($request->has('deskripsi')) {
            $contact->deskripsi = $request->input('deskripsi');
            Log::info('Updating deskripsi to: '.$contact->deskripsi);
        }

        if ($request->has('alamat')) {
            $contact->alamat = $request->input('alamat');
            Log::info('Updating alamat to: '.$contact->alamat);
        }

        if ($request->has('telepon')) {
            $contact->telepon = $request->input('telepon');
            Log::info('Updating telepon to: '.$contact->telepon);
        }

        if ($request->has('email')) {
            $contact->email = $request->input('email');
            Log::info('Updating email to: '.$contact->email);
        }

        if ($request->has('map_embed')) {
            $mapEmbed = $request->input('map_embed');
            $contact->map_embed = $mapEmbed
                ? $this->extractMapSrc($mapEmbed)
                : null;
        }

        $contact->save();

        Log::info('Contact after update:', $contact->toArray());

        // SOCIALS
        // SOCIALS - Perbaikan
        if ($request->has('socials')) {
            $socialsData = $request->input('socials');

            Log::info('Socials data received:', ['socials' => $socialsData]);

            // Decode jika dikirim sebagai JSON string
            if (is_string($socialsData)) {
                $socialsData = json_decode($socialsData, true);
                Log::info('Socials after decode:', $socialsData);
            }

            if (is_array($socialsData)) {
                $contact->socials()->delete();
                Log::info('Deleted existing socials');

                foreach ($socialsData as $social) {
                    if (! empty($social['platform']) && ! empty($social['url'])) {
                        $contact->socials()->create([
                            'platform' => $social['platform'],
                            'url' => $social['url'],
                        ]);
                        Log::info('Created social:', $social);
                    }
                }
            }
        } else {
            Log::info('No socials data in request');
        }

        Log::info('=== UPDATE CONTACT END ===');

        return response()->json([
            'success' => true,
            'message' => 'Contact updated successfully',
            'data' => $contact->load('socials'),
        ]);
    }

    public function destroy($id)
    {
        $contact = Contact::find($id);

        if (! $contact) {
            return response()->json([
                'success' => false,
                'message' => 'Contact not found',
            ], 404);
        }

        // Delete logo if exists
        if ($contact->logo) {
            Storage::disk('public')->delete($contact->logo);
        }

        // Delete socials (cascade should handle this, but just in case)
        $contact->socials()->delete();

        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully',
        ]);
    }
}
