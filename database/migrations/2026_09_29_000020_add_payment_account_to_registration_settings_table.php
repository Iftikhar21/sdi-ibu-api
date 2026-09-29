<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_settings', function (Blueprint $table) {
            $table->string('payment_bank', 100)->nullable()->after('quota_description');
            $table->string('payment_account_number', 100)->nullable()->after('payment_bank');
            $table->string('payment_account_name', 150)->nullable()->after('payment_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('registration_settings', function (Blueprint $table) {
            $table->dropColumn([
                'payment_bank',
                'payment_account_number',
                'payment_account_name',
            ]);
        });
    }
};
