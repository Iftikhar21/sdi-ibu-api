<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versi kecil foto untuk ditampilkan di beranda (carousel & kartu album).
     */
    public function up(): void
    {
        Schema::table('gallery_photos', function (Blueprint $table) {
            $table->string('thumb')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_photos', function (Blueprint $table) {
            $table->dropColumn('thumb');
        });
    }
};
