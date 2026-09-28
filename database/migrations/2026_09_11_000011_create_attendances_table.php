<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Absensi siswa: selalu terkait siswa + kelas + tahun ajaran + tanggal.
     *
     * Satu siswa hanya punya satu absensi per hari (per tahun ajaran),
     * sehingga tidak ada data ganda walau tombol simpan ditekan berulang.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();
            $table->foreignId('classroom_id')
                ->constrained('classrooms')
                ->cascadeOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
            $table->date('date');
            // hadir | izin | sakit | alpa
            $table->string('status', 10);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id', 'date'], 'attendances_unique');
            $table->index(['classroom_id', 'date']);
            $table->index(['academic_year_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
