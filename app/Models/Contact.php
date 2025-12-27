<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $table = 'contact';

    protected $fillable = [
        'logo',
        'deskripsi',
        'alamat',
        'telepon',
        'email',
        'map_embed'
    ];

    public function socials()
    {
        return $this->hasMany(ContactSocial::class, 'contact_id');
    }
}
