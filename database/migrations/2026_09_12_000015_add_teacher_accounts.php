<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun login untuk guru.
     *
     * - teachers.email   : email pribadi guru, dipakai sebagai email login
     * - teachers.user_id : tautan ke akun login (role "guru")
     * - users.must_change_password : wajib ganti password saat login pertama
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('name');
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('email')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });

    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropUnique(['email']);
            $table->dropColumn('email');
        });
    }
};
