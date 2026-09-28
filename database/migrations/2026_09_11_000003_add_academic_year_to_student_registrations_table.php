<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pendaftaran dikaitkan ke Master Tahun Ajaran.
     * Nullable agar data pendaftaran lama tetap aman.
     */
    public function up(): void
    {
        Schema::table('student_registrations', function (Blueprint $table) {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('user_id')
                ->constrained('academic_years')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
