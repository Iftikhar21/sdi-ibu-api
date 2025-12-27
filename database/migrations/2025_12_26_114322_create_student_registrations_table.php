<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_registrations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // DATA ANAK
            $table->string('full_name');
            $table->string('nickname');
            $table->enum('gender', ['L', 'P']);
            $table->string('birth_place');
            $table->date('birth_date');

            // DATA ORANG TUA
            $table->string('father_name');
            $table->string('mother_name');
            $table->text('address');
            $table->string('phone', 20);
            $table->string('contact_email');

            // FILE
            $table->string('photo');
            $table->string('birth_certificate');
            $table->string('family_card');
            $table->string('payment_proof');

            // STATUS
            $table->string('status')->default('submitted');
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_registrations');
    }
};
