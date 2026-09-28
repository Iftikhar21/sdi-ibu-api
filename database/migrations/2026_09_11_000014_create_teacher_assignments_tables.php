<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penugasan guru per tahun ajaran:
     * - homeroom_assignments  : wali kelas (satu kelas satu wali per tahun)
     * - teaching_assignments  : guru pengampu mata pelajaran per kelas
     *
     * Karena setiap baris terikat tahun ajaran, penugasan tahun sebelumnya
     * tidak ikut berubah ketika tahun ajaran baru diatur.
     */
    public function up(): void
    {
        Schema::create('homeroom_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
            $table->foreignId('classroom_id')
                ->constrained('classrooms')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('teachers')
                ->cascadeOnDelete();
            $table->timestamps();

            // Satu kelas hanya punya satu wali kelas per tahun ajaran
            $table->unique(['classroom_id', 'academic_year_id'], 'homeroom_class_year_unique');
            $table->index(['teacher_id', 'academic_year_id'], 'homeroom_teacher_year_index');
        });

        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
            $table->foreignId('classroom_id')
                ->constrained('classrooms')
                ->cascadeOnDelete();
            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('teachers')
                ->cascadeOnDelete();
            $table->timestamps();

            // Satu mata pelajaran di satu kelas hanya punya satu guru pengampu
            $table->unique(
                ['classroom_id', 'academic_year_id', 'subject_id'],
                'teaching_class_year_subject_unique'
            );
            $table->index(['teacher_id', 'academic_year_id'], 'teaching_teacher_year_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
        Schema::dropIfExists('homeroom_assignments');
    }
};
