<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRegistration extends Model
{
    protected $fillable = [
        'user_id',

        'full_name',
        'nickname',
        'gender',
        'birth_place',
        'birth_date',

        'father_name',
        'mother_name',
        'address',
        'phone',
        'contact_email',

        'photo',
        'birth_certificate',
        'family_card',
        'payment_proof',

        'status',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
