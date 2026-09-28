<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jadwal pelajaran: tahun ajaran + kelas + mata pelajaran + guru + hari + jam.
     *
     * Bentrok jam untuk guru dan kelas dicek di backend (rentang jam tidak
     * boleh bertumpuk); unique index menjaga slot kelas yang persis sama
     * tidak tersimpan dua kali.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
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
            $table->string('day', 10); // senin - sabtu
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['academic_year_id', 'classroom_id', 'day'], 'schedules_class_day_index');
            $table->index(['teacher_id', 'day'], 'schedules_teacher_day_index');
            $table->unique(['classroom_id', 'day', 'start_time'], 'schedules_class_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
