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
    ];

    protected $casts = [
        'quota' => 'integer',
    ];
}
