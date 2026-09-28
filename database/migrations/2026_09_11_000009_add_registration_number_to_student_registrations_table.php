<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor pendaftaran unik untuk setiap calon siswa, mis. REG-2026-0001.
     *
     * Data pendaftaran lama ikut diisi nomornya supaya formatnya seragam.
     */
    public function up(): void
    {
        Schema::table('student_registrations', function (Blueprint $table) {
            $table->string('registration_number', 30)
                ->nullable()
                ->unique()
                ->after('id');
        });

        $rows = DB::table('student_registrations')
            ->leftJoin('academic_years', 'academic_years.id', '=', 'student_registrations.academic_year_id')
            ->select(
                'student_registrations.id',
                'student_registrations.created_at',
                'academic_years.start_date'
            )
            ->orderBy('student_registrations.id')
            ->get();

        foreach ($rows as $row) {
            $year = $row->start_date
                ? substr((string) $row->start_date, 0, 4)
                : ($row->created_at ? substr((string) $row->created_at, 0, 4) : date('Y'));

            DB::table('student_registrations')
                ->where('id', $row->id)
                ->update([
                    'registration_number' => 'REG-'.$year.'-'
                        .str_pad((string) $row->id, 4, '0', STR_PAD_LEFT),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('student_registrations', function (Blueprint $table) {
            $table->dropUnique(['registration_number']);
            $table->dropColumn('registration_number');
        });
    }
};
