<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kategori galeri dijadikan tabel master supaya admin bisa
     * menambah/mengubah kategori sendiri lewat panel.
     */
    public function up(): void
    {
        Schema::create('gallery_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Kategori bawaan (sesuai daftar yang sudah dipakai di beranda)
        $defaults = [
            ['name' => 'Pembelajaran', 'slug' => 'pembelajaran'],
            ['name' => 'Dokumentasi', 'slug' => 'dokumentasi'],
            ['name' => "Al-Qur'an", 'slug' => 'al-quran'],
            ['name' => 'Outing', 'slug' => 'outing'],
            ['name' => 'Olahraga', 'slug' => 'olahraga'],
            ['name' => 'HUT RI', 'slug' => 'hut-ri'],
            ['name' => 'Kegiatan Ibadah', 'slug' => 'kegiatan-ibadah'],
            ['name' => 'Kegiatan Wali Murid', 'slug' => 'kegiatan-wali-murid'],
        ];

        foreach ($defaults as $index => $default) {
            DB::table('gallery_categories')->insert([
                'name' => $default['name'],
                'slug' => $default['slug'],
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // galleries.category (teks) diganti relasi ke tabel master
        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignId('gallery_category_id')
                ->nullable()
                ->after('id')
                ->constrained('gallery_categories')
                ->nullOnDelete();
        });

        // Pindahkan data lama (kalau ada) berdasarkan slug kategori
        $categoryIds = DB::table('gallery_categories')->pluck('id', 'slug');
        $fallbackId = $categoryIds['dokumentasi'] ?? null;

        foreach (DB::table('galleries')->get() as $gallery) {
            DB::table('galleries')
                ->where('id', $gallery->id)
                ->update([
                    'gallery_category_id' => $categoryIds[$gallery->category] ?? $fallbackId,
                ]);
        }

        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('category')->default('dokumentasi')->after('id');
        });

        $categories = DB::table('gallery_categories')->pluck('slug', 'id');

        foreach (DB::table('galleries')->get() as $gallery) {
            DB::table('galleries')
                ->where('id', $gallery->id)
                ->update([
                    'category' => $categories[$gallery->gallery_category_id] ?? 'dokumentasi',
                ]);
        }

        Schema::table('galleries', function (Blueprint $table) {
            $table->dropForeign(['gallery_category_id']);
            $table->dropColumn('gallery_category_id');
        });

        Schema::dropIfExists('gallery_categories');
    }
};
