<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationSetting extends Model
{
    protected $fillable = [
        'phase',
        'phase_message',
        'quota',
        'quota_description',
        'payment_bank',
        'payment_account_number',
        'payment_account_name',
    ];

    protected $casts = [
        'quota' => 'integer',
    ];
}
