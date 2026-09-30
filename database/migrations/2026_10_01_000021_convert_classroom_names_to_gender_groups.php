<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('classrooms')
            ->whereRaw('UPPER(TRIM(name)) = ?', ['A'])
            ->update(['name' => 'Ikhwan']);

        DB::table('classrooms')
            ->whereRaw('UPPER(TRIM(name)) = ?', ['B'])
            ->update(['name' => 'Akhwat']);
    }

    public function down(): void
    {
        DB::table('classrooms')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['ikhwan'])
            ->update(['name' => 'A']);

        DB::table('classrooms')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['akhwat'])
            ->update(['name' => 'B']);
    }
};
