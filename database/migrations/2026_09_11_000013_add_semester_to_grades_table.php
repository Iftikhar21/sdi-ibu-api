<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai diikat ke semester (1 = Ganjil, 2 = Genap) supaya satu mata
     * pelajaran bisa punya nilai berbeda per semester pada tahun ajaran yang sama.
     *
     * Unique lama (siswa + mapel + tahun) diganti menjadi menyertakan semester.
     * Sebelum unique lama dihapus, kolom student_id diberi index biasa dulu
     * karena MySQL membutuhkannya untuk foreign key.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('grades', 'semester')) {
            Schema::table('grades', function (Blueprint $table) {
                $table->unsignedTinyInteger('semester')->default(1)->after('academic_year_id');
            });
        }

        $indexes = collect(Schema::getIndexes('grades'))->pluck('name');
        $uniqueLamaAda = $indexes->contains('grades_unique');

        // Index pendukung foreign key student_id (pengganti unique lama)
        if (! $indexes->contains('grades_student_id_index')) {
            Schema::table('grades', function (Blueprint $table) {
                $table->index('student_id', 'grades_student_id_index');
            });
        }

        if ($uniqueLamaAda) {
            Schema::table('grades', function (Blueprint $table) {
                $table->dropUnique('grades_unique');
            });
        }

        $indexes = collect(Schema::getIndexes('grades'))->pluck('name');

        if (! $indexes->contains('grades_unique')) {
            Schema::table('grades', function (Blueprint $table) {
                $table->unique(
                    ['student_id', 'subject_id', 'academic_year_id', 'semester'],
                    'grades_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique('grades_unique');
            $table->unique(['student_id', 'subject_id', 'academic_year_id'], 'grades_unique');
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropIndex('grades_student_id_index');
            $table->dropColumn('semester');
        });
    }
};
