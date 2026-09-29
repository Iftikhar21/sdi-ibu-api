<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('quota')->default(0);
            $table->text('quota_description')->nullable();
            $table->timestamps();
        });

        Schema::create('registration_requirements', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('registration_fees', function (Blueprint $table) {
            $table->id();
            $table->string('program');
            $table->unsignedBigInteger('amount')->default(0);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_fees');
        Schema::dropIfExists('registration_requirements');
        Schema::dropIfExists('registration_settings');
    }
};
