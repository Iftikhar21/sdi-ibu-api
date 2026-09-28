<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Siswa = orang yang sudah diterima sebagai peserta didik.
     *
     * Berbeda dengan student_registrations yang menyimpan proses pendaftaran,
     * tabel ini adalah entitas permanen yang nantinya berkembang ke riwayat
     * kelas, kenaikan kelas, sampai kelulusan/alumni.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();

            // Satu pendaftaran yang diterima hanya menghasilkan satu siswa
            $table->foreignId('registration_id')
                ->unique()
                ->constrained('student_registrations')
                ->cascadeOnDelete();

            // Nomor Induk Siswa. Boleh kosong sampai sekolah menentukan formatnya.
            $table->string('nis')->nullable()->unique();

            // Data dasar, disalin dari pendaftaran sebagai sumber awal
            $table->string('full_name');
            $table->enum('gender', ['L', 'P'])->nullable();
            $table->string('birth_place')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();

            // Tahun masuk mengacu ke Master Tahun Ajaran
            $table->foreignId('admission_year_id')
                ->nullable()
                ->constrained('academic_years')
                ->nullOnDelete();

            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['status', 'admission_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
