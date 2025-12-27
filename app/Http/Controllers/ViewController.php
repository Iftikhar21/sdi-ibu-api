<?php

namespace App\Http\Controllers;

use App\Models\History;
use App\Models\Program;
use App\Models\VisionMision;
use App\Models\News;
use App\Models\Contact;

class ViewController extends Controller
{
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

        // Format thumbnail URLs untuk Program
        $programs->map(function ($program) {
            $program->thumbnail_url = $program->thumbnail
                ? asset('storage/' . $program->thumbnail)
                : asset('images/no-image.png');
            return $program;
        });

        // Format thumbnail URLs untuk Berita
        if ($featuredNews) {
            $featuredNews->thumbnail_url = $featuredNews->thumbnail
                ? asset('storage/' . $featuredNews->thumbnail)
                : asset('images/no-image.png');
        }

        $recentNews->map(function ($news) {
            $news->thumbnail_url = $news->thumbnail
                ? asset('storage/' . $news->thumbnail)
                : asset('images/no-image.png');
            return $news;
        });

        // Format logo URL untuk Kontak
        if ($kontak) {
            $kontak->logo_url = $kontak->logo
                ? asset('storage/' . $kontak->logo)
                : asset('images/no-image.png');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'welcome' => [
                    'sejarah' => $sejarah,
                    'visi_misi' => $visiMisi
                ],
                'programs' => $programs,
                'news' => [
                    'featured' => $featuredNews,
                    'recent' => $recentNews
                ],
                'contact' => $kontak,
                'stats' => [
                    'program_count' => Program::count(),
                    'news_count' => News::count(),
                    'visi_misi_count' => VisionMision::count()
                ]
            ]
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
                'program_count' => $programCount
            ]
        ]);
    }

    // VISI MISI
    public function getVisiMisi()
    {
        $visiMisi = VisionMision::first();

        return response()->json([
            'success' => true,
            'data' => $visiMisi
        ]);
    }

    // PROGRAM
    public function getProgram()
    {
        $programs = Program::where('status', 'published')->get();

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

    // PROGRAM DETAIL
    public function getProgramDetail($slug)
    {
        $program = Program::where('slug', $slug)->first();

        if (!$program) {
            return response()->json([
                'success' => false,
                'message' => 'Program tidak ditemukan'
            ], 404);
        }

        // Format thumbnail URL
        $program->thumbnail_url = $program->thumbnail
            ? asset('storage/' . $program->thumbnail)
            : asset('images/no-image.png');

        // Ambil 4 berita terbaru untuk sidebar
        $latestNews = News::orderBy('created_at', 'desc')
            ->limit(4)
            ->get();

        // Format thumbnail URL untuk berita
        $latestNews->map(function ($news) {
            $news->thumbnail_url = $news->thumbnail
                ? asset('storage/' . $news->thumbnail)
                : asset('images/no-image.png');
            return $news;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'program' => $program,
                'latest_news' => $latestNews
            ]
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
                ? asset('storage/' . $item->thumbnail)
                : asset('images/no-image.png');

            // photos → url
            if ($item->photos) {
                $item->photos->map(function ($photo) {
                    $photo->url = asset('storage/' . $photo->path);
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
                'recommended_news' => $recommendedNews
            ]
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
                ? asset('storage/' . $item->thumbnail)
                : asset('images/no-image.png');

            if ($item->photos) {
                $item->photos->map(function ($photo) {
                    $photo->url = asset('storage/' . $photo->path);
                    return $photo;
                });
            }

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $berita
        ]);
    }

    // BERITA DETAIL
    public function getBeritaDetail($slug)
    {
        $berita = News::with('photos')
            ->where('slug', $slug)
            ->first();

        if (!$berita) {
            return response()->json([
                'success' => false,
                'message' => 'Berita tidak ditemukan'
            ], 404);
        }

        $berita->thumbnail_url = $berita->thumbnail
            ? asset('storage/' . $berita->thumbnail)
            : asset('images/no-image.png');

        // photos → url
        if ($berita->photos) {
            $berita->photos->map(function ($photo) {
                $photo->url = asset('storage/' . $photo->path);
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
                ? asset('storage/' . $news->thumbnail)
                : asset('images/no-image.png');

            if ($news->photos) {
                $news->photos->map(function ($photo) {
                    $photo->url = asset('storage/' . $photo->path);
                    return $photo;
                });
            }

            return $news;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'berita' => $berita,
                'latest_news' => $latestNews
            ]
        ]);
    }

    // KONTAK
    public function getKontak()
    {
        $kontak = Contact::with('socials')->first();

        if ($kontak) {
            $kontak->logo_url = $kontak->logo
                ? asset('storage/' . $kontak->logo)
                : asset('images/no-image.png');
        }

        return response()->json([
            'success' => true,
            'data' => $kontak
        ]);
    }
}
