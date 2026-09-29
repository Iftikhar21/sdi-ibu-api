<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_settings', function (Blueprint $table) {
            $table->string('phase', 20)->default('closed')->after('id');
            $table->text('phase_message')->nullable()->after('phase');
        });

        if (! DB::table('registration_settings')->exists()) {
            DB::table('registration_settings')->insert([
                'phase' => 'closed',
                'quota' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('registration_settings', function (Blueprint $table) {
            $table->dropColumn(['phase', 'phase_message']);
        });
    }
};
