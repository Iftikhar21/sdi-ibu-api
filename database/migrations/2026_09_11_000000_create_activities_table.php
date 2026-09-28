<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konten section Kegiatan: Prestasi dan Agenda Sekolah.
     * Berita memakai tabel `news` yang sudah ada.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // prestasi | agenda
            $table->string('title');
            $table->text('description');
            $table->string('image')->nullable();
            $table->string('thumb')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
