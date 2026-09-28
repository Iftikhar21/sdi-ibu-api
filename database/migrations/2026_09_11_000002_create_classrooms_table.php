<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master Kelas, terhubung ke Tahun Ajaran.
     * Kuota = kapasitas maksimal kelas pada tahun ajaran tersebut.
     */
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('grade_level'); // 1 - 6
            $table->string('name', 10); // A, B, C
            $table->unsignedInteger('quota');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Satu kombinasi tahun ajaran + tingkat + nama kelas tidak boleh ganda
            $table->unique(['academic_year_id', 'grade_level', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
