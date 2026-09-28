<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penempatan siswa (pendaftaran) ke kelas.
     *
     * Riwayat tersimpan: saat siswa dipindahkan, baris lama hanya
     * dinonaktifkan (is_active = false) sehingga asal kelasnya tetap terlacak.
     */
    public function up(): void
    {
        Schema::create('classroom_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_registration_id')
                ->constrained('student_registrations')
                ->cascadeOnDelete();
            $table->foreignId('classroom_id')
                ->constrained('classrooms')
                ->cascadeOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('unassigned_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Mencegah baris penempatan yang persis sama tersimpan dua kali
            $table->unique(
                ['student_registration_id', 'classroom_id', 'academic_year_id'],
                'classroom_placements_unique'
            );
            $table->index(['classroom_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_placements');
    }
};
