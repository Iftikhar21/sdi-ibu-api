<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    protected $table = 'admin';

    protected $fillable = [
        'user_id',
        'phone',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
