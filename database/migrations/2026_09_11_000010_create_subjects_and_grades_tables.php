<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master Mata Pelajaran
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            // Tingkat yang berlaku; null berarti berlaku untuk semua tingkat
            $table->unsignedTinyInteger('grade_level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['grade_level', 'is_active']);
        });

        // Nilai siswa: selalu terkait siswa + mata pelajaran + tahun ajaran
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();
            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
            $table->decimal('score', 5, 2);
            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // Satu siswa hanya punya satu nilai per mata pelajaran per tahun ajaran
            $table->unique(['student_id', 'subject_id', 'academic_year_id'], 'grades_unique');
            $table->index(['academic_year_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
        Schema::dropIfExists('subjects');
    }
};
