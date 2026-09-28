<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menautkan riwayat penempatan kelas ke entitas Siswa.
     *
     * Tabel classroom_placements sudah menyimpan riwayat lengkap
     * (tahun ajaran, kelas, is_active, assigned_at, unassigned_at), jadi
     * tabel yang sama dipakai sebagai riwayat kelas milik siswa.
     * Kolom student_id nullable karena penempatan bisa terjadi lebih dulu
     * dari pembentukan siswa (data Step 2) dan akan diisi otomatis.
     */
    public function up(): void
    {
        Schema::table('classroom_placements', function (Blueprint $table) {
            $table->foreignId('student_id')
                ->nullable()
                ->after('student_registration_id')
                ->constrained('students')
                ->nullOnDelete();

            $table->index(
                ['student_id', 'academic_year_id', 'is_active'],
                'classroom_placements_student_year_active'
            );
        });
    }

    public function down(): void
    {
        Schema::table('classroom_placements', function (Blueprint $table) {
            $table->dropIndex('classroom_placements_student_year_active');
            $table->dropConstrainedForeignId('student_id');
        });
    }
};
