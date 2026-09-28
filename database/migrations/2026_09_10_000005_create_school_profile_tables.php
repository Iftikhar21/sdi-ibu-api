<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel-tabel untuk halaman Profil: Tentang Sekolah, Nilai Pendidikan,
     * Kepala Sekolah, Guru & Tenaga Kependidikan, dan Legalitas/NPSN.
     */
    public function up(): void
    {
        // Tentang Sekolah IBU
        Schema::create('about_schools', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('image')->nullable();
            $table->string('thumb')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Nilai Pendidikan (mis. Iman, Adab, Ilmu, Amal)
        Schema::create('education_values', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Rincian tiap nilai pendidikan
        Schema::create('education_value_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('education_value_id')
                ->constrained('education_values')
                ->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Kepala Sekolah (termasuk periode sebelumnya bila ada)
        Schema::create('principals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('position')->default('Kepala Sekolah');
            $table->string('employee_number')->nullable(); // NIP / NUPTK
            $table->string('photo')->nullable();
            $table->string('thumb')->nullable();
            $table->text('greeting')->nullable(); // sambutan
            $table->json('education_history')->nullable(); // riwayat pendidikan
            $table->string('started_at')->nullable(); // mulai menjabat
            $table->string('ended_at')->nullable(); // selesai menjabat
            $table->boolean('is_active')->default(true); // kepala sekolah saat ini
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Guru & Tenaga Kependidikan
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('gender', ['L', 'P']);
            $table->string('last_education')->nullable();
            $table->string('position')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('photo')->nullable();
            $table->string('thumb')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Legalitas / NPSN
        Schema::create('legalities', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description');
            $table->string('image')->nullable();
            $table->string('thumb')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legalities');
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('principals');
        Schema::dropIfExists('education_value_items');
        Schema::dropIfExists('education_values');
        Schema::dropIfExists('about_schools');
    }
};
