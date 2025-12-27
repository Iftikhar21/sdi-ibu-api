<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSocial extends Model
{
    protected $table = 'contact_socials';
    protected $fillable = ['contact_id', 'platform', 'url'];

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
