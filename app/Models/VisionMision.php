<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VisionMision extends Model
{
    use HasFactory;
    protected $table = 'vision_misions';

    protected $fillable = ['vision', 'missions'];

    protected $casts = [
        'missions' => 'array',
    ];
}
