<?php

namespace App\Support;

use App\Models\AcademicYear;

/**
 * Rentang tanggal satu semester di dalam tahun ajaran.
 *
 * Semester 1 dihitung dari awal tahun ajaran sampai 31 Desember, semester 2
 * dari 1 Januari tahun berikutnya sampai akhir tahun ajaran. Dipakai bersama
 * oleh Rapor dan Dashboard Akademik supaya perhitungannya konsisten.
 */
class SemesterPeriod
{
    /**
     * @return array{from: ?string, to: ?string}
     */
    public static function for(AcademicYear $academicYear, int $semester): array
    {
        $mulai = $academicYear->start_date;
        $selesai = $academicYear->end_date;

        if (! $mulai || ! $selesai) {
            return ['from' => null, 'to' => null];
        }

        if ($semester === 1) {
            return ['from' => $mulai, 'to' => substr($mulai, 0, 4).'-12-31'];
        }

        $tahunKedua = substr($selesai, 0, 4) !== substr($mulai, 0, 4)
            ? substr($selesai, 0, 4)
            : (string) ((int) substr($mulai, 0, 4) + 1);

        return ['from' => $tahunKedua.'-01-01', 'to' => $selesai];
    }
}
