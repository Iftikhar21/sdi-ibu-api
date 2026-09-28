<?php

namespace App\Http\Controllers;

use App\Models\AboutSchool;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\EducationValue;
use App\Models\Faq;
use App\Models\Gallery;
use App\Models\GalleryCategory;
use App\Models\Graduation;
use App\Models\History;
use App\Models\Legality;
use App\Models\News;
use App\Models\OrganizationStructure;
use App\Models\Principal;
use App\Models\Program;
use App\Models\Teacher;
use App\Models\VisionMision;
use Illuminate\Http\Request;

class ViewController extends Controller
{
    /**
     * Lengkapi album galeri dengan URL foto, foto sampul, dan jumlah foto.
     *
     * $withAllPhotos = false dipakai untuk daftar di beranda: hanya foto
     * sampul yang dikirim agar halaman tidak kebanjiran data.
     */
    private function formatGallery(Gallery $gallery, bool $withAllPhotos = true): Gallery
    {
        $gallery->photos->each(function ($photo) {
            $photo->image_url = asset('storage/'.$photo->image);
            $photo->thumb_url = $photo->thumb
                ? asset('storage/'.$photo->thumb)
                : $photo->image_url;
        });

        $firstPhoto = $gallery->photos->first();

        $gallery->cover_url = $firstPhoto
            ? ($firstPhoto->thumb
                ? asset('storage/'.$firstPhoto->thumb)
                : asset('storage/'.$firstPhoto->image))
            : null;
        $gallery->photos_count = $gallery->photos->count();

        if (! $withAllPhotos) {
            $gallery->setRelation('photos', $gallery->photos->take(1)->values());
        }

        return $gallery;
    }

    // BERANDA (HOME)
    public function getHomeData()
    {
        // Sejarah untuk Welcome Section
        $sejarah = History::first();

        // Visi Misi untuk Welcome Section
        $visiMisi = VisionMision::first();

        // Program Unggulan (3 terbaru)
        $programs = Program::where('status', 'published')
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        // Berita Terbaru
        $featuredNews = News::orderBy('created_at', 'desc')
            ->first();

        $recentNews = News::orderBy('created_at', 'desc')
            ->when($featuredNews, function ($query) use ($featuredNews) {
                return $query->where('id', '!=', $featuredNews->id);
            })
            ->limit(2)
            ->get();

        // Kontak Data
        $kontak = Contact::with('socials')->first();

        // FAQ (hanya yang aktif) untuk section tanya jawab di beranda
        $faqs = Faq::where('is_active', true)->ordered()->get();

        // Galeri (hanya yang aktif) untuk beranda.
        // Dibatasi agar halaman tetap ringan walau album sudah banyak;
        // daftar lengkapnya ada di endpoint /gallery-list.
        $galleries = Gallery::with(['photos', 'category'])
            ->where('is_active', true)
            ->ordered()
            ->limit(9)
            ->get()
            ->map(fn ($gallery) => $this->formatGallery($gallery, false))
            ->values();

        // Kegiatan: prestasi & agenda, dibatasi 6 per jenis agar beranda tetap ringan
        $activities = collect();

        foreach (array_keys(Activity::TYPES) as $type) {
            $activities = $activities->merge(
                Activity::where('type', $type)
                    ->where('is_active', true)
                    ->ordered()
                    ->limit(6)
                    ->get()
            );
        }

        // Format thumbnail URLs untuk Program
        $programs->map(function ($program) {
            $program->thumbnail_url = $program->thumbnail
                ? asset('storage/'.$program->thumbnail)
                : asset('images/no-image.png');

            return $program;
        });

        // Format thumbnail URLs untuk Berita
        if ($featuredNews) {
            $featuredNews->thumbnail_url = $featuredNews->thumbnail
                ? asset('storage/'.$featuredNews->thumbnail)
                : asset('images/no-image.png');
        }

        $recentNews->map(function ($news) {
            $news->thumbnail_url = $news->thumbnail
                ? asset('storage/'.$news->thumbnail)
                : asset('images/no-image.png');

            return $news;
        });

        // Format logo URL untuk Kontak
        if ($kontak) {
            $kontak->logo_url = $kontak->logo
                ? asset('storage/'.$kontak->logo)
                : asset('images/no-image.png');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'welcome' => [
                    'sejarah' => $sejarah,
                    'visi_misi' => $visiMisi,
                ],
                'programs' => $programs,
                'news' => [
                    'featured' => $featuredNews,
                    'recent' => $recentNews,
                ],
                'contact' => $kontak,
                'faqs' => $faqs,
                'galleries' => $galleries,
                'activities' => $activities->values(),
                'stats' => [
                    'program_count' => Program::count(),
                    'news_count' => News::count(),
                    'visi_misi_count' => VisionMision::count(),
                ],
            ],
        ]);
    }

    // SEJARAH
    public function getSejarah()
    {
        $sejarah = History::first();
        $programCount = Program::count();

        return response()->json([
            'success' => true,
            'data' => [
                'sejarah' => $sejarah,
                'program_count' => $programCount,
            ],
        ]);
    }

    // VISI MISI
    public function getVisiMisi()
    {
        $visiMisi = VisionMision::first();

        return response()->json([
            'success' => true,
            'data' => $visiMisi,
        ]);
    }

    // PROGRAM
    public function getProgram()
    {
        $programs = Program::where('status', 'published')->get();

        $programs->map(function ($program) {
            $program->thumbnail_url = $program->thumbnail
                ? asset('storage/'.$program->thumbnail)
                : asset('images/no-image.png');

            return $program;
        });

        return response()->json([
            'success' => true,
            'data' => $programs,
        ]);
    }

    // PROGRAM DETAIL
    public function getProgramDetail($slug)
    {
        $program = Program::where('slug', $slug)->first();

        if (! $program) {
            return response()->json([
                'success' => false,
                'message' => 'Program tidak ditemukan',
            ], 404);
        }

        // Format thumbnail URL
        $program->thumbnail_url = $program->thumbnail
            ? asset('storage/'.$program->thumbnail)
            : asset('images/no-image.png');

        // Ambil 4 berita terbaru untuk sidebar
        $latestNews = News::orderBy('created_at', 'desc')
            ->limit(4)
            ->get();

        // Format thumbnail URL untuk berita
        $latestNews->map(function ($news) {
            $news->thumbnail_url = $news->thumbnail
                ? asset('storage/'.$news->thumbnail)
                : asset('images/no-image.png');

            return $news;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'program' => $program,
                'latest_news' => $latestNews,
            ],
        ]);
    }

    public function getBeritaList()
    {
        // Ambil 3 berita dengan views terbanyak sebagai featured news
        $featuredNews = News::with('photos')
            ->orderBy('views', 'desc')
            ->take(4)
            ->get();

        // Ambil berita terbaru, kecuali yang sudah featured
        $recentNews = News::with('photos')
            ->orderBy('created_at', 'desc')
            ->when($featuredNews->isNotEmpty(), function ($query) use ($featuredNews) {
                return $query->whereNotIn('id', $featuredNews->pluck('id'));
            })
            ->limit(6)
            ->get();

        // Ambil berita rekomendasi secara random
        $recommendedNews = News::with('photos')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        // Fungsi format berita (thumbnail & photos)
        $formatNews = function ($item) {
            // thumbnail
            $item->thumbnail_url = $item->thumbnail
                ? asset('storage/'.$item->thumbnail)
                : asset('images/no-image.png');

            // photos → url
            if ($item->photos) {
                $item->photos->map(function ($photo) {
                    $photo->url = asset('storage/'.$photo->path);

                    return $photo;
                });
            }

            return $item;
        };

        // Format setiap berita
        $featuredNews->each($formatNews);
        $recentNews->each($formatNews);
        $recommendedNews->each($formatNews);

        // Kembalikan response JSON
        return response()->json([
            'success' => true,
            'data' => [
                'featured_news' => $featuredNews,
                'recent_news' => $recentNews,
                'recommended_news' => $recommendedNews,
            ],
        ]);
    }

    // BERITA LIST SEMUA (untuk API yang sudah ada)
    public function getBerita()
    {
        $berita = News::with('photos')
            ->orderBy('created_at', 'desc')
            ->get();

        $berita->map(function ($item) {
            $item->thumbnail_url = $item->thumbnail
                ? asset('storage/'.$item->thumbnail)
                : asset('images/no-image.png');

            if ($item->photos) {
                $item->photos->map(function ($photo) {
                    $photo->url = asset('storage/'.$photo->path);

                    return $photo;
                });
            }

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $berita,
        ]);
    }

    // BERITA DETAIL
    public function getBeritaDetail($slug)
    {
        $berita = News::with('photos')
            ->where('slug', $slug)
            ->first();

        if (! $berita) {
            return response()->json([
                'success' => false,
                'message' => 'Berita tidak ditemukan',
            ], 404);
        }

        $berita->thumbnail_url = $berita->thumbnail
            ? asset('storage/'.$berita->thumbnail)
            : asset('images/no-image.png');

        // photos → url
        if ($berita->photos) {
            $berita->photos->map(function ($photo) {
                $photo->url = asset('storage/'.$photo->path);

                return $photo;
            });
        }

        $berita->increment('views');

        $latestNews = News::with('photos')
            ->where('id', '!=', $berita->id)
            ->orderBy('created_at', 'desc')
            ->limit(4)
            ->get();

        $latestNews->map(function ($news) {
            $news->thumbnail_url = $news->thumbnail
                ? asset('storage/'.$news->thumbnail)
                : asset('images/no-image.png');

            if ($news->photos) {
                $news->photos->map(function ($photo) {
                    $photo->url = asset('storage/'.$photo->path);

                    return $photo;
                });
            }

            return $news;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'berita' => $berita,
                'latest_news' => $latestNews,
            ],
        ]);
    }

    // KONTAK
    public function getKontak()
    {
        $kontak = Contact::with('socials')->first();

        if ($kontak) {
            $kontak->logo_url = $kontak->logo
                ? asset('storage/'.$kontak->logo)
                : asset('images/no-image.png');
        }

        return response()->json([
            'success' => true,
            'data' => $kontak,
        ]);
    }

    // FAQ (PUBLIK)
    public function getFaq()
    {
        $faqs = Faq::where('is_active', true)->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $faqs,
        ]);
    }

    // GALERI (PUBLIK) - daftar album dengan filter & paging
    public function getGallery(Request $request)
    {
        $perPage = (int) $request->get('per_page', 12);
        $perPage = min(max($perPage, 1), 24);
        $category = $request->get('category');

        $query = Gallery::with(['photos', 'category'])
            ->where('is_active', true)
            ->ordered();

        if ($category && $category !== 'all') {
            $query->where(function ($subQuery) use ($category) {
                if (is_numeric($category)) {
                    $subQuery->where('gallery_category_id', (int) $category);
                } else {
                    $subQuery->whereHas('category', function ($categoryQuery) use ($category) {
                        $categoryQuery->where('slug', $category);
                    });
                }
            });
        }

        $galleries = $query->paginate($perPage);

        $galleries->getCollection()->transform(
            fn ($gallery) => $this->formatGallery($gallery, false)
        );

        return response()->json([
            'success' => true,
            'data' => $galleries->items(),
            'meta' => [
                'current_page' => $galleries->currentPage(),
                'last_page' => $galleries->lastPage(),
                'per_page' => $galleries->perPage(),
                'total' => $galleries->total(),
            ],
        ]);
    }

    // GALERI (PUBLIK) - detail satu album beserta seluruh fotonya
    public function getGalleryDetail($id)
    {
        $gallery = Gallery::with(['photos', 'category'])
            ->where('is_active', true)
            ->find($id);

        if (! $gallery) {
            return response()->json([
                'success' => false,
                'message' => 'Galeri tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGallery($gallery),
        ]);
    }

    // KATEGORI GALERI (PUBLIK) - dipakai untuk filter di halaman /galeri
    public function getGalleryCategories()
    {
        $categories = GalleryCategory::ordered()
            ->where('is_active', true)
            ->withCount(['galleries' => function ($query) {
                $query->where('is_active', true);
            }])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    // PROFIL (PUBLIK)
    public function getAboutSchool()
    {
        return response()->json([
            'success' => true,
            'data' => AboutSchool::where('is_active', true)->ordered()->get(),
        ]);
    }

    public function getEducationValues()
    {
        return response()->json([
            'success' => true,
            'data' => EducationValue::with('items')
                ->where('is_active', true)
                ->ordered()
                ->get(),
        ]);
    }

    public function getPrincipals()
    {
        // Semua kepala sekolah ditampilkan; kolom is_active menandai
        // siapa yang sedang menjabat (ditampilkan sebagai profil utama).
        return response()->json([
            'success' => true,
            'data' => Principal::ordered()->get(),
        ]);
    }

    public function getTeachers()
    {
        return response()->json([
            'success' => true,
            'data' => Teacher::where('is_active', true)->ordered()->get(),
        ]);
    }

    public function getOrganizationStructures()
    {
        return response()->json([
            'success' => true,
            'data' => OrganizationStructure::where('is_active', true)->ordered()->get(),
        ]);
    }

    public function getLegalities()
    {
        return response()->json([
            'success' => true,
            'data' => Legality::where('is_active', true)->ordered()->get(),
        ]);
    }

    /**
     * Profil Lulusan (publik).
     *
     * Hanya data yang aman ditampilkan: nama, kelas terakhir, dan tahun lulus.
     * Data pribadi seperti alamat, telepon, email, dan dokumen tidak dikirim.
     */
    public function getGraduates(Request $request)
    {
        // Daftar tahun kelulusan yang benar-benar punya lulusan
        $years = Graduation::query()
            ->join('academic_years', 'academic_years.id', '=', 'graduations.graduation_year_id')
            ->selectRaw('academic_years.id as id, academic_years.name as name, count(*) as total')
            ->groupBy('academic_years.id', 'academic_years.name', 'academic_years.start_date')
            ->orderByDesc('academic_years.start_date')
            ->orderByDesc('academic_years.id')
            ->get()
            ->map(fn ($year) => [
                'id' => (int) $year->id,
                'name' => $year->name,
                'total' => (int) $year->total,
            ]);

        $academicYearId = (int) $request->get('academic_year_id');

        if (! $years->contains('id', $academicYearId)) {
            $academicYearId = (int) ($years->first()['id'] ?? 0);
        }

        $graduates = $academicYearId === 0
            ? collect()
            : Graduation::query()
                ->with(['student.classHistories.classroom'])
                ->where('graduation_year_id', $academicYearId)
                ->join('students', 'students.id', '=', 'graduations.student_id')
                ->select('graduations.*')
                ->orderBy('students.full_name')
                ->get()
                ->map(function (Graduation $graduation) {
                    $student = $graduation->student;
                    $placement = $student?->lastClassroomInYear($graduation->graduation_year_id);

                    return [
                        'id' => $student?->id,
                        'name' => $student?->full_name,
                        'last_class' => $placement?->classroom?->display_name,
                    ];
                });

        return response()->json([
            'success' => true,
            'data' => [
                'years' => $years,
                'academic_year' => $years->firstWhere('id', $academicYearId),
                'total' => $graduates->count(),
                'graduates' => $graduates->values(),
            ],
        ]);
    }

    // KEGIATAN (PUBLIK) - Prestasi & Agenda Sekolah
    public function getActivities(Request $request)
    {
        $query = Activity::where('is_active', true)->ordered();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }
}
