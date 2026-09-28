<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data kelulusan siswa.
     *
     * Dibentuk dari proses kelulusan (bukan input manual nama siswa), dan
     * hanya ada satu baris per siswa sehingga satu siswa tidak bisa lulus
     * dua kali pada tahun ajaran berbeda.
     */
    public function up(): void
    {
        Schema::create('graduations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->unique()
                ->constrained('students')
                ->cascadeOnDelete();

            // Tahun kelulusan mengacu ke Master Tahun Ajaran
            $table->foreignId('graduation_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();

            $table->date('graduation_date')->nullable();
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['graduation_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduations');
    }
};
